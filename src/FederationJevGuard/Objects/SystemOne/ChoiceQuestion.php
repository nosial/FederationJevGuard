<?php

    namespace FederationJevGuard\Objects\SystemOne;

    use FederationJevGuard\Interfaces\QuestionInterface;
    use InvalidArgumentException;

    class ChoiceQuestion implements QuestionInterface
    {
        private string|array $instructions;
        private array $criteria;

        /**
         * ChoiceQuestion constructor.
         *
         * @param string|array $instructions What the model decides
         * @param array<string, string|array|null> $criteria The options by name, each with what it covers
         * @throws InvalidArgumentException If there is no option
         */
        public function __construct(string|array $instructions, array $criteria)
        {
            if(count($criteria) === 0)
            {
                throw new InvalidArgumentException('A choice needs at least one option');
            }

            $this->instructions = $instructions;
            $this->criteria = $criteria;
        }

        /**
         * Returns the same question with its options in reverse order. Models can lean towards the first option, a
         * decision is only trusted when both orders agree
         *
         * @return ChoiceQuestion The reversed question
         */
        public function reversed(): ChoiceQuestion
        {
            return new ChoiceQuestion($this->instructions, array_reverse($this->criteria, true));
        }

        /**
         * Returns the names of the options
         *
         * @return string[] The option names
         */
        public function getOptions(): array
        {
            return array_map('strval', array_keys($this->criteria));
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return ['type' => 'choice', 'instructions' => $this->instructions, 'criteria' => $this->criteria];
        }
    }
