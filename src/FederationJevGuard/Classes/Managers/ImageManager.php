<?php

    namespace FederationJevGuard\Classes\Managers;

    use FederationJevGuard\Objects\ImageRecord;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Utilities;
    use FederationLib\Exceptions\DatabaseOperationException;
    use InvalidArgumentException;
    use PDO;
    use PDOException;

    class ImageManager
    {
        /**
         * Returns what the model found in an image
         *
         * @param string $sha256 The SHA-256 of the image file
         * @return ImageRecord|null The findings, null if the image was never judged
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function getImage(string $sha256): ?ImageRecord
        {
            $sha256 = strtolower($sha256);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT sha256, findings, model, UNIX_TIMESTAMP(created) AS created FROM jevguard_images WHERE sha256 = :sha256");
                $stmt->bindParam(':sha256', $sha256);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return $row ? new ImageRecord($row) : null;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to get the findings of the image $sha256: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        /**
         * Stores what the model found in an image, replacing earlier findings
         *
         * @param string $sha256 The SHA-256 of the image file
         * @param array<string, float> $findings The probability of each finding
         * @param string $model The model that judged the image
         * @return void
         * @throws InvalidArgumentException If the hash is invalid
         * @throws DatabaseOperationException If there is an error preparing or executing the SQL statement
         */
        public static function storeImage(string $sha256, array $findings, string $model): void
        {
            $sha256 = strtolower($sha256);
            if(!Utilities::isSha256($sha256))
            {
                throw new InvalidArgumentException('Invalid SHA-256 hash');
            }

            $json = json_encode(array_map(fn($value) => round((float)$value, 4), $findings));

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "INSERT INTO jevguard_images (sha256, findings, model) VALUES (:sha256, :findings, :model) ON DUPLICATE KEY UPDATE findings = VALUES(findings), model = VALUES(model), created = CURRENT_TIMESTAMP()"
                );
                $stmt->bindParam(':sha256', $sha256);
                $stmt->bindParam(':findings', $json);
                $stmt->bindParam(':model', $model);
                $stmt->execute();
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to store the findings of the image $sha256: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }
    }
