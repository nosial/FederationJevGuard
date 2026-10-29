<?php

    namespace FederationJevGuard\Objects\SystemOne;

    use FederationJevGuard\Interfaces\QuestionInterface;

    class NoulQuestion implements QuestionInterface
    {
        private string|array $instructions;
        private string|array|null $true;
        private string|array|null $false;

        /**
         * NoulQuestion constructor.
         *
         * @param string|array $instructions The question, phrased so that yes means the thing checked for is true
         * @param string|array|null $true Optional. What counts as a yes
         * @param string|array|null $false Optional. What counts as a no
         */
        public function __construct(string|array $instructions, string|array|null $true=null, string|array|null $false=null)
        {
            $this->instructions = $instructions;
            $this->true = $true;
            $this->false = $false;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            $question = ['type' => 'noul', 'instructions' => $this->instructions];
            if($this->true !== null || $this->false !== null)
            {
                $question['criteria'] = array_filter(['true' => $this->true, 'false' => $this->false], fn($value) => $value !== null);
            }

            return $question;
        }
    }
