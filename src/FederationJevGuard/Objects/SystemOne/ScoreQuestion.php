<?php

    namespace FederationJevGuard\Objects\SystemOne;

    use FederationJevGuard\Interfaces\QuestionInterface;
    use InvalidArgumentException;

    class ScoreQuestion implements QuestionInterface
    {
        private string|array $instructions;
        private array $levels;

        /**
         * ScoreQuestion constructor.
         *
         * @param string|array $instructions What the model rates
         * @param array $levels The levels from the lowest to the highest, 2 to 10, each describing a situation
         * @throws InvalidArgumentException If there are fewer than 2 or more than 10 levels
         */
        public function __construct(string|array $instructions, array $levels)
        {
            if(count($levels) < 2 || count($levels) > 10)
            {
                throw new InvalidArgumentException('A score needs 2 to 10 levels');
            }

            $this->instructions = $instructions;
            $this->levels = array_values($levels);
        }

        /**
         * Returns the number of the highest level
         *
         * @return int The top level
         */
        public function getTopLevel(): int
        {
            return count($this->levels) - 1;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return ['type' => 'score', 'instructions' => $this->instructions, 'criteria' => $this->levels];
        }
    }
