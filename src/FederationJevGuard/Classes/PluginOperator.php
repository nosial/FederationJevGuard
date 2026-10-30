<?php

    namespace FederationJevGuard\Classes;

    use FederationJevGuard\Enums\MetadataKey;
    use FederationJevGuard\Exceptions\ReviewException;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\OperatorRecord;
    use InvalidArgumentException;
    use PDOException;

    class PluginOperator
    {
        /**
         * The name of the operator, restored at startup if it was changed
         */
        public const string NAME = 'FederationJevGuard';

        private static ?string $uuid = null;
        private static ?bool $active = null;

        /**
         * Overrides whether the plugin operator is active, e.g., for tests without a database
         *
         * @param bool|null $active Whether the operator is active, null to read the operator (and its UUID) again
         * @return void
         */
        public static function setActive(?bool $active): void
        {
            self::$active = $active;
            if($active === null)
            {
                self::$uuid = null;
            }
        }

        /**
         * Creates or repairs the plugin operator: its name and permissions are restored, but a disabled operator stays
         * disabled (the soft switch). The database lock serializes simultaneous startups.
         *
         * @return void
         * @throws ReviewException If another process kept provisioning the operator
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If FederationLib rejects a change of the operator
         */
        public static function provision(): void
        {
            if(!DatabaseLock::acquire(DatabaseLock::OPERATOR, 30))
            {
                throw new ReviewException('Unable to lock FederationJevGuard operator provisioning');
            }

            $connection = DatabaseConnection::getConnection();

            try
            {
                $uuid = DatabaseConnection::getMetadata(MetadataKey::OPERATOR_UUID->value);
                $operator = $uuid ? OperatorManager::getOperator($uuid) : null;
                if($operator === null)
                {
                    // Keep creation and its identity pointer together, including if initialization is interrupted.
                    try
                    {
                        $connection->beginTransaction();
                        $uuid = OperatorManager::createOperator(self::NAME)->getUuid();
                        DatabaseConnection::setMetadata(MetadataKey::OPERATOR_UUID->value, $uuid);
                        $connection->commit();
                    }
                    catch(PDOException $e)
                    {
                        throw new DatabaseOperationException('Failed to create the FederationJevGuard operator: ' . $e->getMessage(), (int)$e->getCode(), $e);
                    }
                    finally
                    {
                        // Only still open if the operator wasn't created
                        if($connection->inTransaction())
                        {
                            $connection->rollBack();
                        }
                    }
                }
                elseif($operator->getName() !== self::NAME)
                {
                    OperatorManager::updateOperatorName($uuid, self::NAME);
                }

                OperatorManager::updatePermissions($uuid, client: true, management: true, operator: false);
                OperatorManager::updateConfiguration($uuid, autoAssignReports: false, autoAssignAutomatedReports: false, isBot: true);
                self::$uuid = $uuid;
            }
            finally
            {
                DatabaseLock::releaseQuietly(DatabaseLock::OPERATOR);
            }
        }

        /**
         * Returns the UUID of the plugin operator
         *
         * @return string|null The UUID, null if the operator was never provisioned
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function getUuid(): ?string
        {
            if(self::$uuid === null)
            {
                $uuid = DatabaseConnection::getMetadata(MetadataKey::OPERATOR_UUID->value);
                self::$uuid = is_string($uuid) && $uuid !== '' ? $uuid : null;
            }

            return self::$uuid;
        }

        /**
         * Checks if the plugin operator exists and is enabled, the plugin does nothing while it isn't. A failure to read
         * the operator counts as disabled, so that the plugin never stands in the way of FederationLib.
         *
         * @return bool True if the plugin may act
         */
        public static function isActive(): bool
        {
            if(self::$active !== null)
            {
                return self::$active;
            }

            try
            {
                $uuid = self::getUuid();
                $operator = $uuid !== null ? OperatorManager::getOperator($uuid) : null;
                return $operator !== null && !$operator->isDisabled();
            }
            catch(DatabaseOperationException|InvalidArgumentException $e)
            {
                Logger::log()->warning('Unable to read the FederationJevGuard operator, the plugin is inactive until it can: ' . $e->getMessage());
                return false;
            }
        }

        /**
         * Returns the plugin operator, the records the plugin creates are attributed to it
         *
         * @return OperatorRecord The operator
         * @throws ReviewException If the operator doesn't exist or is disabled
         * @throws DatabaseOperationException Thrown if there was a database operation error
         * @throws InvalidArgumentException If FederationLib rejects the UUID of the operator
         */
        public static function get(): OperatorRecord
        {
            $uuid = self::getUuid();
            $operator = $uuid !== null ? OperatorManager::getOperator($uuid) : null;
            if($operator === null || $operator->isDisabled())
            {
                throw new ReviewException('The FederationJevGuard operator is unavailable or disabled');
            }

            return $operator;
        }

        /**
         * Checks if the operator is the plugin operator
         *
         * @param string|null $operatorUuid The UUID of the operator
         * @return bool True if it's the plugin operator
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function isPluginOperator(?string $operatorUuid): bool
        {
            return $operatorUuid !== null && self::getUuid() !== null && $operatorUuid === self::getUuid();
        }

        /**
         * Checks if a blacklist record was created by the plugin
         *
         * @param BlacklistRecord $record The blacklist record
         * @return bool True if the plugin created it
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function isPluginBlacklist(BlacklistRecord $record): bool
        {
            return self::isPluginOperator($record->getOperatorUuid());
        }
    }
