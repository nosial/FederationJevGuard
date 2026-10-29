<?php

    namespace FederationJevGuard\Objects;

    class Limits
    {
        public const int TEXT_LENGTH = 4000;
        public const int MIN_TEXT_LENGTH = 160;
        public const int MAX_ITEMS = 40;
        public const int MAX_IMAGES = 4;

        private int $textLength;
        private int $maxItems;
        private int $maxImages;

        /**
         * Limits constructor.
         *
         * @param int $textLength The longest text, in characters
         * @param int $maxItems The most items of a list
         * @param int $maxImages The most images
         */
        public function __construct(int $textLength=self::TEXT_LENGTH, int $maxItems=self::MAX_ITEMS, int $maxImages=self::MAX_IMAGES)
        {
            $this->textLength = max(self::MIN_TEXT_LENGTH, $textLength);
            $this->maxItems = max(1, $maxItems);
            $this->maxImages = max(0, $maxImages);
        }

        /**
         * Returns the longest text, in characters
         *
         * @return int The text length
         */
        public function getTextLength(): int
        {
            return $this->textLength;
        }

        /**
         * Returns the most items of a list
         *
         * @return int The most items
         */
        public function getMaxItems(): int
        {
            return $this->maxItems;
        }

        /**
         * Returns the most images
         *
         * @return int The most images
         */
        public function getMaxImages(): int
        {
            return $this->maxImages;
        }

        /**
         * Returns smaller limits: texts are cut shorter first, then images are left out, then items
         *
         * @return Limits|null The smaller limits, null if they can't get any smaller
         */
        public function shrink(): ?Limits
        {
            if($this->textLength > self::MIN_TEXT_LENGTH)
            {
                return new Limits(intdiv($this->textLength, 2), $this->maxItems, $this->maxImages);
            }

            if($this->maxImages > 0)
            {
                return new Limits($this->textLength, $this->maxItems, intdiv($this->maxImages, 2));
            }

            if($this->maxItems > 1)
            {
                return new Limits($this->textLength, intdiv($this->maxItems, 2), 0);
            }

            return null;
        }
    }
