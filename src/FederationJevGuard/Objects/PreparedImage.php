<?php

    namespace FederationJevGuard\Objects;

    class PreparedImage
    {
        private string $sha256;
        private string $data;
        private int $width;
        private int $height;

        /**
         * PreparedImage constructor.
         *
         * @param string $sha256 The SHA-256 of the original file
         * @param string $data The prepared JPEG
         * @param int $width The width of the prepared image
         * @param int $height The height of the prepared image
         */
        public function __construct(string $sha256, string $data, int $width, int $height)
        {
            $this->sha256 = $sha256;
            $this->data = $data;
            $this->width = $width;
            $this->height = $height;
        }

        /**
         * Returns the SHA-256 of the original file, the same image is only judged once
         *
         * @return string The hash
         */
        public function getSha256(): string
        {
            return $this->sha256;
        }

        /**
         * Returns the prepared JPEG
         *
         * @return string The bytes of the image
         */
        public function getData(): string
        {
            return $this->data;
        }

        /**
         * Returns the reference of the image in a request, it never occurs in text by accident
         *
         * @return string The reference
         */
        public function getReference(): string
        {
            return 'jevguard-image-' . substr($this->sha256, 0, 32);
        }

        /**
         * Returns the width of the prepared image
         *
         * @return int The width in pixels
         */
        public function getWidth(): int
        {
            return $this->width;
        }

        /**
         * Returns the height of the prepared image
         *
         * @return int The height in pixels
         */
        public function getHeight(): int
        {
            return $this->height;
        }
    }
