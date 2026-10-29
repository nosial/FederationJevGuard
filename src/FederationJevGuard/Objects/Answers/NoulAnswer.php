<?php

    namespace FederationJevGuard\Objects\Answers;

    class NoulAnswer
    {
        private float $noul;

        /**
         * NoulAnswer constructor.
         *
         * @param array $data The answer as the endpoint returned it
         */
        public function __construct(array $data)
        {
            $this->noul = max(0.0, min(1.0, (float)($data['noul'] ?? 0.5)));
        }

        /**
         * Returns the probability that the answer is yes, near 0.5 when the model is unsure
         *
         * @return float The probability of yes
         */
        public function getNoul(): float
        {
            return $this->noul;
        }

        /**
         * Returns the answer as an array
         *
         * @return array The answer
         */
        public function toArray(): array
        {
            return [
                'type' => 'noul',
                'noul' => $this->noul
            ];
        }
    }
