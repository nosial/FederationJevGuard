<?php

    namespace FederationJevGuard\Objects;

    use FederationLib\Interfaces\SerializableInterface;

    class ImageRecord implements SerializableInterface
    {
        private string $sha256;
        /** @var array<string, float> */
        private array $findings;
        private string $model;
        private int $created;

        /**
         * ImageRecord constructor.
         *
         * @param array $data A row of jevguard_images, or an array of toArray()
         */
        public function __construct(array $data)
        {
            $this->sha256 = (string)$data['sha256'];
            $findings = $data['findings'] ?? [];
            $findings = is_string($findings) ? (json_decode($findings, true) ?: []) : (is_array($findings) ? $findings : []);
            $this->findings = array_map('floatval', $findings);
            $this->model = (string)($data['model'] ?? '');
            $created = $data['created'] ?? time();
            $this->created = is_numeric($created) ? (int)$created : (strtotime((string)$created) ?: time());
        }

        /**
         * Returns the SHA-256 of the image file
         *
         * @return string The hash
         */
        public function getSha256(): string
        {
            return $this->sha256;
        }

        /**
         * Returns the probability of each finding about the image, eg; promotional => 0.93
         *
         * @return array<string, float> The findings
         */
        public function getFindings(): array
        {
            return $this->findings;
        }

        /**
         * Returns the highest probability of the findings that point to spam or abuse
         *
         * @return float The probability
         */
        public function getAbuseProbability(): float
        {
            return count($this->findings) > 0 ? max($this->findings) : 0.0;
        }

        /**
         * Returns the model that judged the image
         *
         * @return string The model
         */
        public function getModel(): string
        {
            return $this->model;
        }

        /**
         * Returns when the image was judged
         *
         * @return int The Unix timestamp
         */
        public function getCreated(): int
        {
            return $this->created;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return ['sha256' => $this->sha256, 'findings' => $this->findings, 'model' => $this->model, 'created' => $this->created];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ImageRecord
        {
            return new self($array);
        }
    }
