<?php

    namespace FederationJevGuard\Classes\Managers;

    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Exceptions\DatabaseOperationException;
    use PDO;
    use PDOException;

    class UsageManager
    {
        /**
         * Records a request made to the System One endpoint
         *
         * @param int $inputTokens The billable input tokens of the request
         * @param int $outputTokens The output tokens of the request
         * @param bool $failed True if the request failed
         * @return void
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function recordRequest(int $inputTokens, int $outputTokens, bool $failed=false): void
        {
            $failures = $failed ? 1 : 0;

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "INSERT INTO jevguard_usage (day, requests, input_tokens, output_tokens, failures) VALUES (UTC_DATE(), 1, :input_tokens, :output_tokens, :failures) ON DUPLICATE KEY UPDATE requests = requests + 1, input_tokens = input_tokens + VALUES(input_tokens), output_tokens = output_tokens + VALUES(output_tokens), failures = failures + VALUES(failures)"
                );
                $stmt->bindParam(':input_tokens', $inputTokens, PDO::PARAM_INT);
                $stmt->bindParam(':output_tokens', $outputTokens, PDO::PARAM_INT);
                $stmt->bindParam(':failures', $failures, PDO::PARAM_INT);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to record the use of the System One endpoint: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Returns today's use of the System One endpoint (UTC)
         *
         * @return array{requests: int, input_tokens: int, output_tokens: int, failures: int} The use
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function getToday(): array
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT requests, input_tokens, output_tokens, failures FROM jevguard_usage WHERE day = UTC_DATE()");
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                return [
                    'requests' => (int)($row['requests'] ?? 0),
                    'input_tokens' => (int)($row['input_tokens'] ?? 0),
                    'output_tokens' => (int)($row['output_tokens'] ?? 0),
                    'failures' => (int)($row['failures'] ?? 0),
                ];
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to get the use of the System One endpoint: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Removes the use of days before a time
         *
         * @param int $before The Unix timestamp
         * @return void
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function purge(int $before): void
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM jevguard_usage WHERE day < DATE(FROM_UNIXTIME(:before))");
                $stmt->bindParam(':before', $before, PDO::PARAM_INT);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException('Failed to remove the old use of the System One endpoint: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }
    }
