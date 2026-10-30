<?php

    namespace FederationJevGuard\Objects\Answers;

    class ChoiceAnswer
    {
        private string $choice;
        private ?float $confidence;
        /** @var array<string, float> */
        private array $probabilities;

        /**
         * ChoiceAnswer constructor.
         *
         * @param array $data The answer as the endpoint returned it
         */
        public function __construct(array $data)
        {
            $this->probabilities = [];
            foreach(is_array($data['probabilities'] ?? null) ? $data['probabilities'] : [] as $option => $probability)
            {
                $this->probabilities[(string)$option] = max(0.0, min(1.0, (float)$probability));
            }

            $choice = isset($data['choice']) ? (string)$data['choice'] : null;
            if($choice === null && count($this->probabilities) > 0)
            {
                $choice = (string)array_keys($this->probabilities, max($this->probabilities))[0];
            }

            $this->choice = $choice ?? '';
            $this->confidence = isset($data['confidence']) ? (float)$data['confidence'] : null;
        }

        /**
         * Returns the selected option, the one with the highest probability
         *
         * @return string The option
         */
        public function getChoice(): string
        {
            return $this->choice;
        }

        /**
         * Returns how peaked the distribution is, from 0 (an even split) to 1 (all of it on one option)
         *
         * @return float|null The confidence, null if the endpoint returned none
         */
        public function getConfidence(): ?float
        {
            return $this->confidence;
        }

        /**
         * Returns the probability of each option
         *
         * @return array<string, float> The probabilities by option
         */
        public function getProbabilities(): array
        {
            return $this->probabilities;
        }

        /**
         * Returns the probability of an option, 0 if the endpoint returned none for it. Without any probabilities the
         * selected option counts as certain, eg; a self-hosted endpoint that only returns the choice
         *
         * @param string $option The option
         * @return float The probability
         */
        public function getProbability(string $option): float
        {
            if(count($this->probabilities) === 0)
            {
                return $option === $this->choice ? 1.0 : 0.0;
            }

            return $this->probabilities[$option] ?? 0.0;
        }

        /**
         * Returns the answer as an array
         *
         * @return array The answer
         */
        public function toArray(): array
        {
            return [
                'type' => 'choice',
                'choice' => $this->choice,
                'confidence' => $this->confidence,
                'probabilities' => $this->probabilities
            ];
        }
    }
