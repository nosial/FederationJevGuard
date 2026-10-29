<?php

    namespace FederationJevGuard\Objects;

    use FederationJevGuard\Objects\SystemOne\SystemOneResponse;

    class Evaluation
    {
        private SystemOneResponse $response;
        private array $state;
        private int $images;

        /**
         * Evaluation constructor.
         *
         * @param SystemOneResponse $response The answers
         * @param array $state The state the questions were asked about
         * @param int $images The number of images sent with the state
         */
        public function __construct(SystemOneResponse $response, array $state, int $images)
        {
            $this->response = $response;
            $this->state = $state;
            $this->images = $images;
        }

        /**
         * Returns the answers
         *
         * @return SystemOneResponse The answers
         */
        public function getResponse(): SystemOneResponse
        {
            return $this->response;
        }

        /**
         * Returns the state the questions were asked about
         *
         * @return array The state
         */
        public function getState(): array
        {
            return $this->state;
        }

        /**
         * Returns the number of images sent with the state
         *
         * @return int The number of images
         */
        public function getImages(): int
        {
            return $this->images;
        }
    }
