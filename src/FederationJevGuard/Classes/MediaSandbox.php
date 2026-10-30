<?php

    namespace FederationJevGuard\Classes;

    class MediaSandbox
    {
        /**
         * The memory the sandbox may use
         */
        public const string MEMORY_LIMIT = '256M';

        /**
         * The CPU seconds the sandbox may use
         */
        public const int CPU_SECONDS = 15;

        /**
         * The wall-clock seconds the sandbox may take before it's killed
         */
        public const int WALL_SECONDS = 20;

        /**
         * The largest output that is accepted from the sandbox
         */
        public const int MAX_OUTPUT = 8388608;

        /**
         * The functions the sandbox can't call: processes, network, files, environment and extension loading
         */
        private const array DISABLED_FUNCTIONS = [
            'exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen', 'pcntl_exec', 'pcntl_fork', 'dl', 'putenv',
            'mail', 'curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client',
            'stream_socket_server', 'socket_create', 'file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'symlink',
            'link', 'chmod', 'touch', 'tempnam', 'tmpfile', 'ini_set', 'set_time_limit', 'ignore_user_abort',
        ];

        /**
         * The program the sandbox runs: it decodes the image of its standard input, rotates it upright, fits it to the
         * limits of the System One endpoint and writes it to its standard output as a baseline JPEG. Exit codes: 3 too
         * large, 4 not a supported image or too many pixels, 5 undecodable, 6 GD missing, 7 encoding failed
         */
        private const string PROGRAM = <<<'PHP'
            [, $maxBytes, $maxPixels, $minSide, $maxSide, $quality] = array_map('intval', $argv);
            if(!extension_loaded('gd')) { exit(6); }
            $data = stream_get_contents(STDIN, $maxBytes + 1);
            if(!is_string($data) || $data === '' || strlen($data) > $maxBytes) { exit(3); }
            $info = @getimagesizefromstring($data);
            if(!is_array($info) || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP], true)
                || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > $maxPixels) { exit(4); }
            $image = @imagecreatefromstring($data);
            if(!$image instanceof GdImage || imagesx($image) * imagesy($image) > $maxPixels) { exit(5); }
            if($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data'))
            {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $data);
                rewind($stream);
                $exif = @exif_read_data($stream);
                $rotated = match(is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1) { 3 => imagerotate($image, 180, 0), 6 => imagerotate($image, -90, 0), 8 => imagerotate($image, 90, 0), default => $image };
                $image = $rotated instanceof GdImage ? $rotated : $image;
            }
            unset($data);
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1.0, $maxSide / max($width, $height));
            if(min($width, $height) * $scale < $minSide) { $scale = min($minSide / min($width, $height), $maxSide / max($width, $height)); }
            $scaledWidth = max(1, (int)round($width * $scale));
            $scaledHeight = max(1, (int)round($height * $scale));
            $canvasWidth = min($maxSide, max($minSide, $scaledWidth));
            $canvasHeight = min($maxSide, max($minSide, $scaledHeight));
            $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, intdiv($canvasWidth - $scaledWidth, 2), intdiv($canvasHeight - $scaledHeight, 2), 0, 0, $scaledWidth, $scaledHeight, $width, $height);
            imageinterlace($canvas, false);
            ob_start();
            if(!imagejpeg($canvas, null, $quality)) { ob_end_clean(); exit(7); }
            fwrite(STDOUT, (string)ob_get_clean());
            exit(0);
            PHP;

        private static ?bool $available = null;

        /**
         * Checks if the sandbox can be used: processes can be started and the PHP command-line binary exists. Whether
         * GD is available to it is only known once it runs
         *
         * @return bool True if the sandbox can be used
         */
        public static function isAvailable(): bool
        {
            if(self::$available === null)
            {
                $binary = self::getBinary();
                self::$available = function_exists('proc_open') && $binary !== null;
                if(!self::$available)
                {
                    Logger::log()->warning('Images are not processed: the PHP command-line binary or proc_open() is unavailable to run the media sandbox');
                }
            }

            return self::$available;
        }

        /**
         * Decodes, fits and re-encodes an image in the sandbox
         *
         * @param string $data The untrusted file
         * @param int $maxBytes The largest file accepted
         * @param int $maxPixels The most pixels an image may have
         * @param int $minSide The shortest side of the result
         * @param int $maxSide The longest side of the result
         * @param int $quality The JPEG quality of the result
         * @return string|null The re-encoded JPEG, null if the file was rejected or the sandbox failed
         */
        public static function reencode(string $data, int $maxBytes, int $maxPixels, int $minSide, int $maxSide, int $quality): ?string
        {
            if(!self::isAvailable() || strlen($data) > $maxBytes)
            {
                return null;
            }

            $command = [self::getBinary(), '-n'];
            foreach(self::getSettings() as [$name, $value])
            {
                array_push($command, '-d', $name . '=' . $value);
            }

            array_push($command, '-r', self::PROGRAM, '--', (string)$maxBytes, (string)$maxPixels, (string)$minSide, (string)$maxSide, (string)$quality);

            // No shell, no environment, no working directory to read from
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', []);
            if(!is_resource($process))
            {
                Logger::log()->warning('Unable to start the media sandbox, the image is not processed');
                return null;
            }

            $output = self::communicate($process, $pipes, $data);
            $status = self::finish($process, $output === null);
            if($output === null || $status !== 0)
            {
                Logger::log()->debug(sprintf('The media sandbox rejected an image (exit code %s)', $status ?? 'killed'));
                if($status === 6)
                {
                    Logger::log()->warning('Images are not processed: the GD extension is not available to the PHP command-line binary');
                }

                return null;
            }

            return $output;
        }

        /**
         * Writes the file to the sandbox and reads its output within the time limit
         *
         * @param resource $process The sandbox
         * @param array $pipes Its standard input, output and error
         * @param string $data The file
         * @return string|null The output, null if it took too long or was too large
         */
        private static function communicate($process, array $pipes, string $data): ?string
        {
            $deadline = microtime(true) + self::WALL_SECONDS;
            stream_set_blocking($pipes[0], false);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $written = 0;
            $output = '';
            $open = [1 => $pipes[1], 2 => $pipes[2]];
            while(count($open) > 0 || $written < strlen($data))
            {
                $timeout = $deadline - microtime(true);
                if($timeout <= 0)
                {
                    return null;
                }

                $read = array_values($open);
                $write = $written < strlen($data) && is_resource($pipes[0]) ? [$pipes[0]] : [];
                $except = null;
                if(@stream_select($read, $write, $except, (int)$timeout, (int)(fmod($timeout, 1) * 1000000)) === false)
                {
                    return null;
                }

                if(count($write) > 0)
                {
                    $chunk = @fwrite($pipes[0], substr($data, $written, 65536));
                    if($chunk === false || $chunk === 0)
                    {
                        // The sandbox stopped reading, eg; it rejected the file early
                        $written = strlen($data);
                    }
                    else
                    {
                        $written += $chunk;
                    }

                    if($written >= strlen($data))
                    {
                        @fclose($pipes[0]);
                    }
                }

                foreach($read as $stream)
                {
                    $chunk = (string)@fread($stream, 65536);
                    if($stream === $pipes[1])
                    {
                        $output .= $chunk;
                        if(strlen($output) > self::MAX_OUTPUT)
                        {
                            return null;
                        }
                    }

                    if(feof($stream))
                    {
                        unset($open[array_search($stream, $open, true)]);
                    }
                }
            }

            return $output;
        }

        /**
         * Closes the sandbox, killing it if it's still running
         *
         * @param resource $process The sandbox
         * @param bool $kill True to kill it
         * @return int|null Its exit code, null if it was killed
         */
        private static function finish($process, bool $kill): ?int
        {
            if($kill)
            {
                @proc_terminate($process, 9);
                @proc_close($process);
                return null;
            }

            $status = @proc_close($process);
            return $status >= 0 ? $status : null;
        }

        /**
         * Returns the restrictions of the sandbox
         *
         * @return array<int, array{0: string, 1: string}> The PHP settings, as name and value pairs
         */
        private static function getSettings(): array
        {
            // -n leaves out every ini file, the image extensions are loaded explicitly when they're not built in
            $settings = [];
            foreach(['gd', 'exif'] as $extension)
            {
                if(extension_loaded($extension) && !self::isBuiltIn($extension))
                {
                    $settings[] = ['extension', $extension];
                }
            }

            return array_merge($settings, [
                ['memory_limit', self::MEMORY_LIMIT],
                ['max_execution_time', (string)self::CPU_SECONDS],
                ['open_basedir', '/nonexistent'],
                ['allow_url_fopen', '0'],
                ['allow_url_include', '0'],
                ['disable_functions', implode(',', self::DISABLED_FUNCTIONS)],
                ['display_errors', '0'],
                ['log_errors', '0'],
                ['auto_prepend_file', ''],
                ['auto_append_file', ''],
                ['enable_dl', '0'],
            ]);
        }

        /**
         * Checks if an extension is compiled into PHP rather than loaded from a shared library
         *
         * @param string $extension The extension
         * @return bool True if it's built in
         */
        private static function isBuiltIn(string $extension): bool
        {
            $directory = (string)ini_get('extension_dir');
            return $directory === '' || !is_file($directory . DIRECTORY_SEPARATOR . $extension . '.so');
        }

        /**
         * Returns the PHP command-line binary: the running one in the command line, the one next to php-fpm otherwise
         *
         * @return string|null The path of the binary, null if there is none
         */
        private static function getBinary(): ?string
        {
            $candidates = PHP_SAPI === 'cli' ? [PHP_BINARY] : [];
            $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
            foreach($candidates as $candidate)
            {
                if(is_string($candidate) && $candidate !== '' && is_file($candidate) && is_executable($candidate))
                {
                    return $candidate;
                }
            }

            return null;
        }
    }
