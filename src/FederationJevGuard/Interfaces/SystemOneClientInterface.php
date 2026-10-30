<?php

    namespace FederationJevGuard\Interfaces;

    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\Objects\SystemOne\ImageAsset;
    use FederationJevGuard\Objects\SystemOne\SystemOneResponse;

    interface SystemOneClientInterface
    {
        /**
         * Asks the questions about the state in one request
         *
         * @param string|array $state The content every question refers to
         * @param array<string, QuestionInterface> $questions The questions by name
         * @param ImageAsset[] $assets Optional. The images whose references appear in the state or the questions
         * @param int|null $timeout Optional. The timeout in seconds, the configured timeout when null
         * @param int $retries Optional. How often a rate limited or overloaded request is tried again
         * @return SystemOneResponse The answers
         * @throws SystemOneException If the request failed
         */
        public function evaluate(string|array $state, array $questions, array $assets=[], ?int $timeout=null, int $retries=2): SystemOneResponse;
    }
