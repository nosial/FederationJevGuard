<?php

    namespace FederationJevGuard\Classes\Managers;

    use FederationJevGuard\Enums\DecisionOutcome;
    use FederationJevGuard\Enums\SubjectType;
    use FederationJevGuard\Objects\DecisionRecord;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use Symfony\Component\Uid\Uuid;

    class DecisionManager
    {
        /**
         * Records a decision
         *
         * @param SubjectType $subjectType What was decided about
         * @param string $subject The UUID of the subject
         * @param string|null $entity The UUID of the entity the subject concerns, left out if it no longer exists
         * @param DecisionOutcome $outcome What came of the decision
         * @param string|null $verdict What the model decided
         * @param float|null $probability The probability of the verdict
         * @param string|null $reason Why the decision has its outcome
         * @param string|null $record The UUID of the record the decision created, eg; the blacklist record
         * @param string|null $inputHash The SHA-256 of what the decision was made on
         * @param string|null $model The model that answered
         * @param array|null $answers Every answer of the model
         * @param int $inputTokens The input tokens of the requests the decision took
         * @return string The UUID of the decision
         * @throws InvalidArgumentException If a UUID is invalid
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function record(SubjectType $subjectType, string $subject, ?string $entity, DecisionOutcome $outcome, ?string $verdict=null, ?float $probability=null, ?string $reason=null, ?string $record=null, ?string $inputHash=null, ?string $model=null, ?array $answers=null, int $inputTokens=0): string
        {
            if(!Validate::uuid($subject) || ($entity !== null && !Validate::uuid($entity)))
            {
                throw new InvalidArgumentException('Invalid subject or entity UUID');
            }

            if($entity !== null && !EntitiesManager::entityExistsByUuid($entity))
            {
                $entity = null;
            }

            $uuid = Uuid::v7()->toRfc4122();
            $type = $subjectType->value;
            $outcomeValue = $outcome->value;
            $reason = $reason !== null ? mb_substr($reason, 0, 255) : null;
            $answersJson = $answers !== null ? json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "INSERT INTO jevguard_decisions (uuid, subject_type, subject, entity, outcome, verdict, probability, reason, record, input_hash, model, answers, input_tokens) VALUES (:uuid, :subject_type, :subject, :entity, :outcome, :verdict, :probability, :reason, :record, :input_hash, :model, :answers, :input_tokens)"
                );
                $stmt->bindParam(':uuid', $uuid);
                $stmt->bindParam(':subject_type', $type);
                $stmt->bindParam(':subject', $subject);
                $stmt->bindParam(':entity', $entity);
                $stmt->bindParam(':outcome', $outcomeValue);
                $stmt->bindParam(':verdict', $verdict);
                $stmt->bindValue(':probability', $probability !== null ? round($probability, 4) : null);
                $stmt->bindParam(':reason', $reason);
                $stmt->bindParam(':record', $record);
                $stmt->bindParam(':input_hash', $inputHash);
                $stmt->bindParam(':model', $model);
                $stmt->bindParam(':answers', $answersJson);
                $stmt->bindParam(':input_tokens', $inputTokens, PDO::PARAM_INT);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to record the decision about $type $subject: " . $e->getMessage(), (int)$e->getCode(), $e);
            }

            return $uuid;
        }

        /**
         * Returns a decision
         *
         * @param string $uuid The UUID of the decision
         * @return DecisionRecord|null The decision, null if it doesn't exist
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function getDecision(string $uuid): ?DecisionRecord
        {
            if(!Validate::uuid($uuid))
            {
                return null;
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM jevguard_decisions WHERE uuid = :uuid");
                $stmt->bindParam(':uuid', $uuid);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return $row ? new DecisionRecord($row) : null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to get the decision $uuid: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Returns the latest decision of the model about a subject, the records of what operators did are left out
         *
         * @param SubjectType $subjectType What was decided about
         * @param string $subject The UUID of the subject
         * @return DecisionRecord|null The decision, null if there is none
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function getLatestDecision(SubjectType $subjectType, string $subject): ?DecisionRecord
        {
            $type = $subjectType->value;
            $suppressed = DecisionOutcome::SUPPRESSED->value;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT * FROM jevguard_decisions WHERE subject_type = :subject_type AND subject = :subject AND outcome != :suppressed ORDER BY created DESC, uuid DESC LIMIT 1"
                );
                $stmt->bindParam(':subject_type', $type);
                $stmt->bindParam(':subject', $subject);
                $stmt->bindParam(':suppressed', $suppressed);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return $row ? new DecisionRecord($row) : null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to get the latest decision about $type $subject: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Returns the decision that created a record, eg; the blacklist record of an entity
         *
         * @param string $record The UUID of the record
         * @return DecisionRecord|null The decision, null if no decision created the record
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function getDecisionByRecord(string $record): ?DecisionRecord
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT * FROM jevguard_decisions WHERE record = :record ORDER BY created DESC LIMIT 1");
                $stmt->bindParam(':record', $record);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return $row ? new DecisionRecord($row) : null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to get the decision that created $record: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Returns when an operator last lifted or deleted a blacklist record of the entity, as recorded by the plugin
         *
         * @param string $entity The UUID of the entity
         * @return int|null The Unix timestamp, null if the plugin recorded none
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function getLastSuppression(string $entity): ?int
        {
            $type = SubjectType::ENTITY->value;
            $suppressed = DecisionOutcome::SUPPRESSED->value;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT UNIX_TIMESTAMP(MAX(created)) FROM jevguard_decisions WHERE subject_type = :subject_type AND subject = :subject AND outcome = :suppressed"
                );
                $stmt->bindParam(':subject_type', $type);
                $stmt->bindParam(':subject', $entity);
                $stmt->bindParam(':suppressed', $suppressed);
                $stmt->execute();
                $timestamp = $stmt->fetchColumn();

                return $timestamp !== false && $timestamp !== null ? (int)$timestamp : null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to get the last suppression of $entity: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Removes the decisions made before a time, the suppressions are kept so that operators' decisions are always
         * respected
         *
         * @param int $before The Unix timestamp
         * @return int The number of decisions removed
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function purge(int $before): int
        {
            $suppressed = DecisionOutcome::SUPPRESSED->value;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "DELETE FROM jevguard_decisions WHERE created < FROM_UNIXTIME(:before) AND outcome != :suppressed LIMIT 10000"
                );
                $stmt->bindParam(':before', $before, PDO::PARAM_INT);
                $stmt->bindParam(':suppressed', $suppressed);
                $stmt->execute();

                return $stmt->rowCount();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to remove old decisions: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }
    }
