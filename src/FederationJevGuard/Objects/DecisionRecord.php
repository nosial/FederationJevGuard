<?php

    namespace FederationJevGuard\Objects;

    use FederationJevGuard\Enums\DecisionOutcome;
    use FederationJevGuard\Enums\SubjectType;
    use FederationLib\Interfaces\SerializableInterface;

    class DecisionRecord implements SerializableInterface
    {
        private string $uuid;
        private SubjectType $subjectType;
        private string $subject;
        private ?string $entity;
        private DecisionOutcome $outcome;
        private ?string $verdict;
        private ?float $probability;
        private ?string $reason;
        private ?string $record;
        private ?string $inputHash;
        private ?string $model;
        private ?string $questions;
        private ?array $answers;
        private int $inputTokens;
        private int $created;

        /**
         * DecisionRecord constructor.
         *
         * @param array $data A row of jevguard_decisions, or an array of toArray()
         */
        public function __construct(array $data)
        {
            $this->uuid = (string)$data['uuid'];
            $this->subjectType = SubjectType::from((string)$data['subject_type']);
            $this->subject = (string)$data['subject'];
            $this->entity = isset($data['entity']) ? (string)$data['entity'] : null;
            $this->outcome = DecisionOutcome::from((string)$data['outcome']);
            $this->verdict = isset($data['verdict']) ? (string)$data['verdict'] : null;
            $this->probability = isset($data['probability']) ? (float)$data['probability'] : null;
            $this->reason = isset($data['reason']) ? (string)$data['reason'] : null;
            $this->record = isset($data['record']) ? (string)$data['record'] : null;
            $this->inputHash = isset($data['input_hash']) ? (string)$data['input_hash'] : null;
            $this->model = isset($data['model']) ? (string)$data['model'] : null;
            $this->questions = isset($data['questions']) ? (string)$data['questions'] : null;
            $answers = $data['answers'] ?? null;
            $this->answers = is_string($answers) ? (json_decode($answers, true) ?: null) : (is_array($answers) ? $answers : null);
            $this->inputTokens = (int)($data['input_tokens'] ?? 0);
            $created = $data['created'] ?? time();
            $this->created = is_numeric($created) ? (int)$created : (strtotime((string)$created) ?: time());
        }

        /**
         * Returns the UUID of the decision
         *
         * @return string The UUID
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Returns what was decided about
         *
         * @return SubjectType The subject type
         */
        public function getSubjectType(): SubjectType
        {
            return $this->subjectType;
        }

        /**
         * Returns the UUID of the report, evidence record, entity or attachment
         *
         * @return string The UUID of the subject
         */
        public function getSubject(): string
        {
            return $this->subject;
        }

        /**
         * Returns the UUID of the entity the subject concerns, eg; the reported entity
         *
         * @return string|null The UUID of the entity
         */
        public function getEntity(): ?string
        {
            return $this->entity;
        }

        /**
         * Returns what came of the decision
         *
         * @return DecisionOutcome The outcome
         */
        public function getOutcome(): DecisionOutcome
        {
            return $this->outcome;
        }

        /**
         * Returns what the model decided, eg; MALICIOUS or BLACKLIST
         *
         * @return string|null The verdict
         */
        public function getVerdict(): ?string
        {
            return $this->verdict;
        }

        /**
         * Returns the probability of the verdict
         *
         * @return float|null The probability
         */
        public function getProbability(): ?float
        {
            return $this->probability;
        }

        /**
         * Returns why the decision has its outcome
         *
         * @return string|null The reason
         */
        public function getReason(): ?string
        {
            return $this->reason;
        }

        /**
         * Returns the UUID of the record the decision created, eg; the blacklist record
         *
         * @return string|null The UUID of the record
         */
        public function getRecord(): ?string
        {
            return $this->record;
        }

        /**
         * Returns the SHA-256 of what the decision was made on
         *
         * @return string|null The hash
         */
        public function getInputHash(): ?string
        {
            return $this->inputHash;
        }

        /**
         * Returns the model that answered
         *
         * @return string|null The model
         */
        public function getModel(): ?string
        {
            return $this->model;
        }

        /**
         * Returns the version of the questions the model was asked, see QuestionSet::getVersion()
         *
         * @return string|null The version, null if no model answered or the decision predates it
         */
        public function getQuestions(): ?string
        {
            return $this->questions;
        }

        /**
         * Returns every answer of the model
         *
         * @return array|null The answers by question
         */
        public function getAnswers(): ?array
        {
            return $this->answers;
        }

        /**
         * Returns the input tokens of the requests the decision took
         *
         * @return int The input tokens
         */
        public function getInputTokens(): int
        {
            return $this->inputTokens;
        }

        /**
         * Returns when the decision was made
         *
         * @return int The Unix timestamp
         */
        public function getCreated(): int
        {
            return $this->created;
        }

        /**
         * Returns the decision as an array
         *
         * @param bool $includeAnswers True to include every answer of the model
         * @return array The decision
         */
        public function toArray(bool $includeAnswers=true): array
        {
            $array = [
                'uuid' => $this->uuid,
                'subject_type' => $this->subjectType->value,
                'subject' => $this->subject,
                'entity' => $this->entity,
                'outcome' => $this->outcome->value,
                'verdict' => $this->verdict,
                'probability' => $this->probability,
                'reason' => $this->reason,
                'record' => $this->record,
                'input_hash' => $this->inputHash,
                'model' => $this->model,
                'questions' => $this->questions,
                'input_tokens' => $this->inputTokens,
                'created' => $this->created,
            ];

            if($includeAnswers)
            {
                $array['answers'] = $this->answers;
            }

            return $array;
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): DecisionRecord
        {
            return new self($array);
        }
    }
