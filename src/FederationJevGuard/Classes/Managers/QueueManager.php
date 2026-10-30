<?php

    namespace FederationJevGuard\Classes\Managers;

    use FederationJevGuard\Enums\SubjectType;
    use FederationJevGuard\Objects\QueueRecord;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use InvalidArgumentException;
    use PDO;
    use PDOException;

    class QueueManager
    {
        /**
         * Queues a subject for review, a subject already queued keeps its place unless it's now due sooner
         *
         * @param SubjectType $subjectType What is reviewed
         * @param string $subject The UUID of the subject
         * @param int $delay Optional. The seconds from now at which the subject is reviewed
         * @return void
         * @throws InvalidArgumentException If the UUID is invalid
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function enqueue(SubjectType $subjectType, string $subject, int $delay=0): void
        {
            if(!Validate::uuid($subject))
            {
                throw new InvalidArgumentException('Invalid subject UUID');
            }

            $type = $subjectType->value;
            $delay = max(0, $delay);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "INSERT INTO jevguard_queue (subject_type, subject, due) VALUES (:subject_type, :subject, NOW() + INTERVAL :delay SECOND) ON DUPLICATE KEY UPDATE due = LEAST(due, VALUES(due))"
                );
                $stmt->bindParam(':subject_type', $type);
                $stmt->bindParam(':subject', $subject);
                $stmt->bindParam(':delay', $delay, PDO::PARAM_INT);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to queue the review of $type $subject: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Returns the subjects that are due, the longest waiting first
         *
         * @param int $limit The most subjects to return
         * @return QueueRecord[] The subjects
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function getDue(int $limit): array
        {
            $limit = max(1, $limit);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "SELECT subject_type, subject, UNIX_TIMESTAMP(due) AS due, attempts, UNIX_TIMESTAMP(created) AS created FROM jevguard_queue WHERE due <= NOW() ORDER BY due LIMIT :limit"
                );
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return array_map(fn(array $row) => new QueueRecord($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to get the due reviews: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Removes a subject from the queue, after it was reviewed
         *
         * @param SubjectType $subjectType What was reviewed
         * @param string $subject The UUID of the subject
         * @return void
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function remove(SubjectType $subjectType, string $subject): void
        {
            $type = $subjectType->value;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM jevguard_queue WHERE subject_type = :subject_type AND subject = :subject");
                $stmt->bindParam(':subject_type', $type);
                $stmt->bindParam(':subject', $subject);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to remove the review of $type $subject: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Postpones a subject whose review failed, counting the attempt
         *
         * @param SubjectType $subjectType What is reviewed
         * @param string $subject The UUID of the subject
         * @param int $seconds The seconds from now at which it's tried again
         * @return void
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function postpone(SubjectType $subjectType, string $subject, int $seconds): void
        {
            $type = $subjectType->value;
            $seconds = max(1, $seconds);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "UPDATE jevguard_queue SET attempts = attempts + 1, due = NOW() + INTERVAL :seconds SECOND WHERE subject_type = :subject_type AND subject = :subject"
                );
                $stmt->bindParam(':seconds', $seconds, PDO::PARAM_INT);
                $stmt->bindParam(':subject_type', $type);
                $stmt->bindParam(':subject', $subject);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to postpone the review of $type $subject: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Returns the number of subjects in the queue
         *
         * @param bool $dueOnly True to only count the subjects that are due
         * @return int The number of subjects
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function count(bool $dueOnly=false): int
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare($dueOnly
                    ? "SELECT COUNT(*) FROM jevguard_queue WHERE due <= NOW()"
                    : "SELECT COUNT(*) FROM jevguard_queue");
                $stmt->execute();

                return (int)$stmt->fetchColumn();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to count the queued reviews: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }
    }
