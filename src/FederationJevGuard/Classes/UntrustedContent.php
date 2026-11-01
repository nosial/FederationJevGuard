<?php

    namespace FederationJevGuard\Classes;

    use FederationLib\Classes\Configuration as FederationConfiguration;
    use FederationLib\Objects\FileAttachmentRecord;
    use FederationLib\Objects\Plugin\ScanFile;

    class UntrustedContent
    {
        /**
         * Reads the beginning of an attachment's file. The file must be a regular file inside FederationLib's storage
         * directory (never a symbolic link or a path outside of it), and at most the given number of bytes are read
         * whatever its record claims
         *
         * @param FileAttachmentRecord $attachment The attachment
         * @param int $maxBytes The most bytes to read
         * @param bool $complete True to return nothing for a file larger than the limit, rather than its beginning
         * @return string|null The bytes, null if the file can't be read safely
         */
        public static function readAttachment(FileAttachmentRecord $attachment, int $maxBytes, bool $complete=true): ?string
        {
            $storage = realpath(FederationConfiguration::getServerConfiguration()->getStoragePath());
            if($storage === false || preg_match('/^[a-f0-9-]{36}$/i', $attachment->getUuid()) !== 1)
            {
                return null;
            }

            $path = $storage . DIRECTORY_SEPARATOR . $attachment->getUuid();
            $real = realpath($path);
            if($real === false || $real !== $path || is_link($path) || !is_file($path) || !is_readable($path))
            {
                return null;
            }

            $handle = @fopen($path, 'rb');
            if($handle === false)
            {
                return null;
            }

            try
            {
                $data = @fread($handle, $maxBytes + 1);
            }
            finally
            {
                fclose($handle);
            }

            if(!is_string($data))
            {
                return null;
            }

            if(strlen($data) > $maxBytes)
            {
                return $complete ? null : substr($data, 0, $maxBytes);
            }

            return $data;
        }

        /**
         * Reads a file submitted for scanning. The file must be a regular file inside PHP's temporary upload directory
         * (never a symbolic link or a path outside of it), and at most the given number of bytes are read whatever its
         * size
         *
         * @param ScanFile $file The file
         * @param int $maxBytes The most bytes to read, a larger file is not read
         * @return string|null The bytes, null if the file can't be read safely or is larger than the limit
         */
        public static function readScanFile(ScanFile $file, int $maxBytes): ?string
        {
            // Uploaded files are in the upload directory, the files FederationLib retrieves from file URLs in the
            // temporary directory
            $directories = array_filter(array_unique([
                realpath((string)(ini_get('upload_tmp_dir') ?: sys_get_temp_dir())),
                realpath(sys_get_temp_dir()),
            ]));
            $path = $file->getPath();
            $real = realpath($path);
            if(count($directories) === 0 || $real === false || $real !== $path
                || !array_any($directories, fn(string $directory) => str_starts_with($real, $directory . DIRECTORY_SEPARATOR))
                || is_link($path) || !is_file($path) || !is_readable($path) || $file->getSize() > $maxBytes)
            {
                return null;
            }

            $handle = @fopen($path, 'rb');
            if($handle === false)
            {
                return null;
            }

            try
            {
                $data = @fread($handle, $maxBytes + 1);
            }
            finally
            {
                fclose($handle);
            }

            return is_string($data) && strlen($data) <= $maxBytes ? $data : null;
        }

        /**
         * Cleans a text: invalid UTF-8 is replaced and control characters other than tabs and line breaks are removed,
         * eg; NUL bytes or terminal escape sequences
         *
         * @param string $text The text
         * @return string The clean text
         */
        public static function sanitizeText(string $text): string
        {
            $text = mb_scrub($text, 'UTF-8');
            return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        }
    }
