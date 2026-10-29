<?php

    namespace FederationJevGuard\Objects\Answers;

    class ScoreAnswer
    {
        private float $score;
        private ?float $confidence;
        /** @var array<int, float> */
        private array $probabilities;

        /**
         * ScoreAnswer constructor.
         *
         * @param array $data The answer as the endpoint returned it
         */
        public function __construct(array $data)
        {
            $this->score = max(0.0, (float)($data['score'] ?? 0.0));
            $this->confidence = isset($data['confidence']) ? (float)$data['confidence'] : null;
            $this->probabilities = [];
            foreach(is_array($data['probabilities'] ?? null) ? $data['probabilities'] : [] as $level => $probability)
            {
                $this->probabilities[(int)$level] = max(0.0, min(1.0, (float)$probability));
            }

            ksort($this->probabilities);
        }

        /**
         * Returns the expected level, the probability-weighted average of the levels, it can fall between two levels
         *
         * @return float The score
         */
        public function getScore(): float
        {
            return $this->score;
        }

        /**
         * Returns how concentrated the distribution is around its most likely level, from 0 to 1
         *
         * @return float|null The confidence, null if the endpoint returned none
         */
        public function getConfidence(): ?float
        {
            return $this->confidence;
        }

        /**
         * Returns the probability of each level
         *
         * @return array<int, float> The probabilities by level
         */
        public function getProbabilities(): array
        {
            return $this->probabilities;
        }

        /**
         * Returns the answer as an array
         *
         * @return array The answer
         */
        public function toArray(): array
        {
            return [
                'type' => 'score',
                'score' => $this->score,
                'confidence' => $this->confidence,
                'probabilities' => $this->probabilities
            ];
        }
    }
