<?php

    namespace FederationJevGuard\Objects\SystemOne;

    use FederationJevGuard\Objects\Answers\ChoiceAnswer;
    use FederationJevGuard\Objects\Answers\NoulAnswer;
    use FederationJevGuard\Objects\Answers\ScoreAnswer;
    use InvalidArgumentException;

    class SystemOneResponse
    {
        private string $model;
        /** @var array<string, NoulAnswer|ChoiceAnswer|ScoreAnswer> */
        private array $answers;
        private int $inputTokens;
        private int $outputTokens;

        /**
         * SystemOneResponse constructor.
         *
         * @param array $data The response body
         * @param string $requestedModel The model the request asked for, used when the response doesn't name one
         * @throws InvalidArgumentException If the response holds no answers
         */
        public function __construct(array $data, string $requestedModel)
        {
            if(!is_array($data['answers'] ?? null) || count($data['answers']) === 0)
            {
                throw new InvalidArgumentException('The response holds no answers');
            }

            $this->model = is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $requestedModel;
            $this->answers = [];
            foreach($data['answers'] as $name => $answer)
            {
                if(!is_array($answer))
                {
                    continue;
                }

                $this->answers[(string)$name] = match($answer['type'] ?? null)
                {
                    'noul' => new NoulAnswer($answer),
                    'score' => new ScoreAnswer($answer),
                    default => new ChoiceAnswer($answer)
                };
            }

            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $this->inputTokens = max(0, (int)($usage['input_tokens'] ?? 0));
            $this->outputTokens = max(0, (int)($usage['output_tokens'] ?? 0));
        }

        /**
         * Returns the model that answered, the versioned ID when the request asked for an alias
         *
         * @return string The model
         */
        public function getModel(): string
        {
            return $this->model;
        }

        /**
         * Checks if a question was answered
         *
         * @param string $name The name of the question
         * @return bool True if it was answered
         */
        public function has(string $name): bool
        {
            return isset($this->answers[$name]);
        }

        /**
         * Returns the answer to a yes/no question, a value of 0.5 (unsure) if it's missing
         *
         * @param string $name The name of the question
         * @return NoulAnswer The answer
         */
        public function noul(string $name): NoulAnswer
        {
            $answer = $this->answers[$name] ?? null;
            return $answer instanceof NoulAnswer ? $answer : new NoulAnswer(['noul' => 0.5]);
        }

        /**
         * Returns the answer to a choice question
         *
         * @param string $name The name of the question
         * @return ChoiceAnswer|null The answer, null if it's missing
         */
        public function choice(string $name): ?ChoiceAnswer
        {
            $answer = $this->answers[$name] ?? null;
            return $answer instanceof ChoiceAnswer ? $answer : null;
        }

        /**
         * Returns the answer to a score question
         *
         * @param string $name The name of the question
         * @return ScoreAnswer|null The answer, null if it's missing
         */
        public function score(string $name): ?ScoreAnswer
        {
            $answer = $this->answers[$name] ?? null;
            return $answer instanceof ScoreAnswer ? $answer : null;
        }

        /**
         * Returns the billable input tokens of the request
         *
         * @return int The input tokens
         */
        public function getInputTokens(): int
        {
            return $this->inputTokens;
        }

        /**
         * Returns the output tokens of the request
         *
         * @return int The output tokens
         */
        public function getOutputTokens(): int
        {
            return $this->outputTokens;
        }

        /**
         * Returns every answer as an array, by the names of the questions
         *
         * @return array The answers
         */
        public function getAnswers(): array
        {
            return array_map(fn($answer) => $answer->toArray(), $this->answers);
        }
    }
