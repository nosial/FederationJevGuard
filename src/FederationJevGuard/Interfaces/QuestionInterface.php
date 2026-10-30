<?php

    namespace FederationJevGuard\Interfaces;

    interface QuestionInterface
    {
        /**
         * Returns the question as it's sent to the System One endpoint
         *
         * @return array The question
         */
        public function toArray(): array;
    }
