<?php

    namespace FederationJevGuard\Classes;

    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Exceptions\DatabaseOperationException;
    use InvalidArgumentException;
    use PDO;
    use PDOException;

    class DatabaseLock
    {
        public const string QUEUE = 'jevguard_queue';
        public const string OPERATOR = 'jevguard_operator';
        public const string SUBJECT = 'jevguard_subject:';
        private const int MAX_NAME_LENGTH = 51;

        /**
         * Acquires a lock
         *
         * @param string $name The name of the lock, at most 51 characters
         * @param int $timeout The number of seconds to wait for the lock, 0 to give up right away
         * @return bool True if the lock was acquired, false if another connection holds it
         * @throws InvalidArgumentException If the name is too long
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function acquire(string $name, int $timeout=0): bool
        {
            self::validateName($name);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT GET_LOCK(CONCAT(:name, ':', LEFT(SHA2(COALESCE(DATABASE(), ''), 256), 12)), :timeout)");
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':timeout', $timeout, PDO::PARAM_INT);
                $stmt->execute();

                return (int)$stmt->fetchColumn() === 1;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to acquire the database lock %s: %s", $name, $e->getMessage()), (int)$e->getCode(), $e);
            }
        }

        /**
         * Releases a lock acquired by this connection
         *
         * @param string $name The name of the lock
         * @return void
         * @throws InvalidArgumentException If the name is too long
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function release(string $name): void
        {
            self::validateName($name);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT RELEASE_LOCK(CONCAT(:name, ':', LEFT(SHA2(COALESCE(DATABASE(), ''), 256), 12)))");
                $stmt->bindParam(':name', $name);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException(sprintf("Failed to release the database lock %s: %s", $name, $e->getMessage()), (int)$e->getCode(), $e);
            }
        }

        /**
         * Releases a lock, logging a failure instead of throwing it, for the finally blocks of the lock's holders: the
         * lock is released with the connection anyway
         *
         * @param string $name The name of the lock
         * @return void
         */
        public static function releaseQuietly(string $name): void
        {
            try
            {
                self::release($name);
            }
            catch(DatabaseOperationException|InvalidArgumentException $e)
            {
                Logger::log()->warning($e->getMessage());
            }
        }

        /**
         * Returns the name of the lock held while a subject is reviewed and acted on
         *
         * @param string $subjectType The type of the subject
         * @param string $uuid The UUID of the subject
         * @return string The name of the lock
         */
        public static function getSubjectLock(string $subjectType, string $uuid): string
        {
            return self::SUBJECT . substr(hash('sha256', $subjectType . ':' . $uuid), 0, self::MAX_NAME_LENGTH - strlen(self::SUBJECT));
        }

        /**
         * Validates the name of a lock
         *
         * @param string $name The name of the lock
         * @return void
         * @throws InvalidArgumentException If the name is empty or too long
         */
        private static function validateName(string $name): void
        {
            if($name === '' || strlen($name) > self::MAX_NAME_LENGTH)
            {
                throw new InvalidArgumentException(sprintf('The name of a database lock must be 1 to %d characters long', self::MAX_NAME_LENGTH));
            }
        }
    }
