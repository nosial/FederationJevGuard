<?php

    namespace FederationJevGuard\Classes;

    use FederationJevGuard\Objects\PreparedImage;
    use FederationJevGuard\Objects\SystemOne\ImageAsset;

    class ImageCollector
    {
        private int $maxImages;
        /** @var array<string, PreparedImage> */
        private array $images = [];

        /**
         * ImageCollector constructor.
         *
         * @param int $maxImages The most images of the state, 0 when images are not sent
         */
        public function __construct(int $maxImages)
        {
            $this->maxImages = Configuration::isImagesEnabled() ? max(0, $maxImages) : 0;
        }

        /**
         * Adds an image to the state
         *
         * @param PreparedImage $image The image
         * @return string|null The reference to put in the state, null if the state holds no more images
         */
        public function add(PreparedImage $image): ?string
        {
            if(!isset($this->images[$image->getSha256()]))
            {
                if(count($this->images) >= $this->maxImages)
                {
                    return null;
                }

                $this->images[$image->getSha256()] = $image;
            }

            return $image->getReference();
        }

        /**
         * Returns the number of images of the state
         *
         * @return int The number of images
         */
        public function count(): int
        {
            return count($this->images);
        }

        /**
         * Returns the images as the assets of a request
         *
         * @return ImageAsset[] The assets
         */
        public function getAssets(): array
        {
            return array_values(array_map(fn(PreparedImage $image) => new ImageAsset($image->getReference(), $image->getData(), 'jpg'), $this->images));
        }
    }
