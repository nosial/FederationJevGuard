<?php

    namespace FederationJevGuard\Objects;

    class Verdict
    {
        private string $verdict;
        private float $probability;
        private bool $confident;
        private string $reason;
        private array $details;

        /**
         * Verdict constructor.
         *
         * @param string $verdict What the model decided, eg; MALICIOUS or BLACKLIST
         * @param float $probability The probability of the verdict
         * @param bool $confident True if the verdict may be acted on
         * @param string $reason Why the verdict is or isn't confident
         * @param array $details Optional. What else was decided, eg; the incident type or a duration
         */
        public function __construct(string $verdict, float $probability, bool $confident, string $reason, array $details=[])
        {
            $this->verdict = $verdict;
            $this->probability = max(0.0, min(1.0, $probability));
            $this->confident = $confident;
            $this->reason = $reason;
            $this->details = $details;
        }

        /**
         * Returns what the model decided
         *
         * @return string The verdict
         */
        public function getVerdict(): string
        {
            return $this->verdict;
        }

        /**
         * Returns the probability of the verdict
         *
         * @return float The probability
         */
        public function getProbability(): float
        {
            return $this->probability;
        }

        /**
         * Returns True if the verdict may be acted on
         *
         * @return bool True if confident
         */
        public function isConfident(): bool
        {
            return $this->confident;
        }

        /**
         * Returns why the verdict is or isn't confident
         *
         * @return string The reason
         */
        public function getReason(): string
        {
            return $this->reason;
        }

        /**
         * Returns a detail of the verdict
         *
         * @param string $name The name of the detail
         * @param mixed $default The value if the detail is missing
         * @return mixed The detail
         */
        public function getDetail(string $name, mixed $default=null): mixed
        {
            return $this->details[$name] ?? $default;
        }

        /**
         * Returns the details of the verdict
         *
         * @return array The details
         */
        public function getDetails(): array
        {
            return $this->details;
        }
    }
