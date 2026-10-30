<?php

    namespace FederationJevGuard\Classes;

    use FederationJevGuard\Classes\Configuration\SystemOneConfiguration;
    use FederationJevGuard\Classes\Managers\UsageManager;
    use FederationJevGuard\Exceptions\BudgetExceededException;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\FederationJevGuard;
    use FederationJevGuard\Interfaces\QuestionInterface;
    use FederationJevGuard\Objects\Evaluation;
    use FederationJevGuard\Objects\Limits;
    use FederationLib\Exceptions\DatabaseOperationException;

    class Evaluator
    {
        public const int CHARS_PER_TOKEN = 3;
        public const int IMAGE_TOKENS = 1600;
        private const int CONTEXT_RETRIES = 2;
        private const int MIN_BUDGET = 800;

        /**
         * Asks the questions about a state
         *
         * @param callable(Limits, ImageCollector): array $build Builds the state within the limits, adding its images
         *                                                       to the collector
         * @param array<string, QuestionInterface> $questions The questions by name
         * @param int|null $timeout Optional. The timeout in seconds, the configured timeout when null
         * @param int $retries Optional. How often a rate limited or overloaded request is tried again
         * @return Evaluation The answers and the state they were given for
         * @throws SystemOneException If the request failed
         * @throws BudgetExceededException If the daily token budget is used up
         * @throws DatabaseOperationException If the use of the endpoint can't be read
         */
        public static function evaluate(callable $build, array $questions, ?int $timeout=null, int $retries=2): Evaluation
        {
            $configuration = Configuration::getSystemOneConfiguration();
            self::checkBudget($configuration);

            $budget = self::getBudget($questions, $configuration);
            for($attempt = 0; ; $attempt++)
            {
                [$state, $images] = self::fit($build, $budget);

                try
                {
                    $response = FederationJevGuard::getClient()->evaluate($state, $questions, $images->getAssets(), $timeout, $retries);
                }
                catch(SystemOneException $e)
                {
                    self::recordUsage(0, 0, true);
                    if(!$e->isContextExceeded() || $attempt >= self::CONTEXT_RETRIES || $budget <= self::MIN_BUDGET)
                    {
                        throw $e;
                    }

                    $budget = max(self::MIN_BUDGET, intdiv($budget, 2));
                    Logger::log()->warning(sprintf('The System One endpoint rejected a state for its size, building it again for %d characters: %s', $budget, $e->getMessage()));
                    continue;
                }

                self::recordUsage($response->getInputTokens(), $response->getOutputTokens(), false);
                return new Evaluation($response, $state, $images->count());
            }
        }

        /**
         * Builds the state with smaller limits until it fits the budget
         *
         * @param callable(Limits, ImageCollector): array $build Builds the state
         * @param int $budget The characters the state may take
         * @return array{0: array, 1: ImageCollector} The state and its images
         */
        public static function fit(callable $build, int $budget): array
        {
            $limits = new Limits();
            while(true)
            {
                $images = new ImageCollector($limits->getMaxImages());
                $state = $build($limits, $images);
                $size = strlen((string)json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))
                    + $images->count() * self::IMAGE_TOKENS * self::CHARS_PER_TOKEN;

                $smaller = $size > $budget ? $limits->shrink() : null;
                if($smaller === null)
                {
                    if($size > $budget)
                    {
                        Logger::log()->debug(sprintf('A state is %d characters at its smallest, over its budget of %d', $size, $budget));
                    }

                    return [$state, $images];
                }

                $limits = $smaller;
            }
        }

        /**
         * Returns the characters a state may take with the questions: the state and any one question have to fit the
         * context of a question, the state and every question the context of the request (twice as large on TypeSafe's
         * API, the same on a self-hosted endpoint)
         *
         * @param array<string, QuestionInterface> $questions The questions
         * @param SystemOneConfiguration $configuration The endpoint and its limits
         * @return int The budget in characters
         */
        public static function getBudget(array $questions, SystemOneConfiguration $configuration): int
        {
            $sizes = array_map(fn(QuestionInterface $question) => strlen((string)json_encode($question->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $questions);
            $context = $configuration->getContextTokens() * self::CHARS_PER_TOKEN;
            $perQuestion = $context - (count($sizes) > 0 ? max($sizes) : 0);
            $perRequest = $context * ($configuration->isTypeSafe() ? 2 : 1) - array_sum($sizes);

            return max(self::MIN_BUDGET, (int)(min($perQuestion, $perRequest) * 0.9));
        }

        /**
         * Checks if the daily token budget allows another request
         *
         * @param SystemOneConfiguration $configuration The endpoint and its limits
         * @return void
         * @throws BudgetExceededException If the budget is used up
         * @throws DatabaseOperationException If the use of the endpoint can't be read
         */
        private static function checkBudget(SystemOneConfiguration $configuration): void
        {
            if($configuration->getDailyTokens() > 0 && UsageManager::getToday()['input_tokens'] >= $configuration->getDailyTokens())
            {
                throw new BudgetExceededException(sprintf('The daily budget of %d input tokens is used up', $configuration->getDailyTokens()));
            }
        }

        /**
         * Records the use of the endpoint, a failure is only logged
         *
         * @param int $inputTokens The input tokens
         * @param int $outputTokens The output tokens
         * @param bool $failed True if the request failed
         * @return void
         */
        private static function recordUsage(int $inputTokens, int $outputTokens, bool $failed): void
        {
            try
            {
                UsageManager::recordRequest($inputTokens, $outputTokens, $failed);
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->warning('Unable to record the use of the System One endpoint: ' . $e->getMessage());
            }
        }
    }
