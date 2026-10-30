<?php

    namespace FederationJevGuard\Classes;

    use FederationJevGuard\Objects\PreparedImage;

    class ImagePreparer
    {
        public const int MIN_SIDE = 280;
        public const int MAX_SIDE = 1568;
        public const int MAX_FILE_SIZE = 20971520;
        public const int MAX_PIXELS = 40000000;
        private const int JPEG_QUALITY = 85;
        private const array SIGNATURES = ["\xFF\xD8\xFF", "\x89PNG\r\n\x1A\n", 'GIF87a', 'GIF89a', 'BM'];

        /**
         * Checks if a file starts with the signature of a supported image, without parsing it
         *
         * @param string $data The untrusted file
         * @return bool True if it claims to be a supported image
         */
        public static function isImage(string $data): bool
        {
            if(strlen($data) < 12)
            {
                return false;
            }

            if(str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP')
            {
                return true;
            }

            return array_any(self::SIGNATURES, fn($signature) => str_starts_with($data, $signature));

        }

        /**
         * Prepares a file in the media sandbox
         *
         * @param string $data The untrusted file
         * @return PreparedImage|null The prepared image, null if it's not a supported image, it's too large, it can't be
         *                            decoded, or the sandbox is unavailable
         */
        public static function prepare(string $data): ?PreparedImage
        {
            if(strlen($data) > self::MAX_FILE_SIZE || !self::isImage($data))
            {
                return null;
            }

            $jpeg = MediaSandbox::reencode($data, self::MAX_FILE_SIZE, self::MAX_PIXELS, self::MIN_SIDE, self::MAX_SIDE, self::JPEG_QUALITY);
            if($jpeg === null || !str_starts_with($jpeg, "\xFF\xD8\xFF"))
            {
                return null;
            }

            // The sandbox's output is only trusted once it's a JPEG within the endpoint's limits
            $info = @getimagesizefromstring($jpeg);
            if(!is_array($info) || $info[2] !== IMAGETYPE_JPEG || $info[0] < self::MIN_SIDE || $info[0] > self::MAX_SIDE || $info[1] < self::MIN_SIDE || $info[1] > self::MAX_SIDE)
            {
                Logger::log()->warning('The media sandbox returned an image outside of the limits, it is not used');
                return null;
            }

            return new PreparedImage(hash('sha256', $data), $jpeg, $info[0], $info[1]);
        }
    }
