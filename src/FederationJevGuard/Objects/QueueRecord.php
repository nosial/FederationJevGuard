<?php

    namespace FederationJevGuard\Objects;

    use FederationJevGuard\Enums\SubjectType;
    use FederationLib\Interfaces\SerializableInterface;

    class QueueRecord implements SerializableInterface
    {
        private SubjectType $subjectType;
        private string $subject;
        private int $due;
        private int $attempts;
        private int $created;

        /**
         * QueueRecord constructor.
         *
         * @param array $data A row of jevguard_queue, or an array of toArray()
         */
        public function __construct(array $data)
        {
            $this->subjectType = SubjectType::from((string)$data['subject_type']);
            $this->subject = (string)$data['subject'];
            $this->due = self::timestamp($data['due'] ?? null);
            $this->attempts = (int)($data['attempts'] ?? 0);
            $this->created = self::timestamp($data['created'] ?? null);
        }

        /**
         * Returns what is reviewed
         *
         * @return SubjectType The subject type
         */
        public function getSubjectType(): SubjectType
        {
            return $this->subjectType;
        }

        /**
         * Returns the UUID of the subject
         *
         * @return string The UUID
         */
        public function getSubject(): string
        {
            return $this->subject;
        }

        /**
         * Returns from when the subject is reviewed
         *
         * @return int The Unix timestamp
         */
        public function getDue(): int
        {
            return $this->due;
        }

        /**
         * Returns the number of reviews that failed
         *
         * @return int The attempts
         */
        public function getAttempts(): int
        {
            return $this->attempts;
        }

        /**
         * Returns when the subject was queued
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
            return [
                'subject_type' => $this->subjectType->value,
                'subject' => $this->subject,
                'due' => $this->due,
                'attempts' => $this->attempts,
                'created' => $this->created,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): QueueRecord
        {
            return new self($array);
        }

        /**
         * Parses a timestamp of a row
         *
         * @param mixed $value A Unix timestamp or a date
         * @return int The Unix timestamp
         */
        private static function timestamp(mixed $value): int
        {
            if(is_numeric($value))
            {
                return (int)$value;
            }

            return is_string($value) ? (strtotime($value) ?: time()) : time();
        }
    }
