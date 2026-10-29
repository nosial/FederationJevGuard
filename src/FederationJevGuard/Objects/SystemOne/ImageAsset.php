<?php

    namespace FederationJevGuard\Objects\SystemOne;

    class ImageAsset
    {
        private string $reference;
        private string $data;
        private string $format;

        /**
         * ImageAsset constructor.
         *
         * @param string $reference A reference that can't occur in the text by accident, eg; a random UUID
         * @param string $data The image file, a PNG or JPEG prepared to the endpoint's limits
         * @param string $format png or jpg
         */
        public function __construct(string $reference, string $data, string $format)
        {
            $this->reference = $reference;
            $this->data = $data;
            $this->format = $format;
        }

        /**
         * Returns the reference of the image
         *
         * @return string The reference
         */
        public function getReference(): string
        {
            return $this->reference;
        }

        /**
         * Returns the image file
         *
         * @return string The bytes of the image
         */
        public function getData(): string
        {
            return $this->data;
        }

        /**
         * Returns the format of the image
         *
         * @return string png or jpg
         */
        public function getFormat(): string
        {
            return $this->format;
        }

        /**
         * Returns the MIME type of the image
         *
         * @return string The MIME type
         */
        public function getMimeType(): string
        {
            return $this->format === 'png' ? 'image/png' : 'image/jpeg';
        }
    }
