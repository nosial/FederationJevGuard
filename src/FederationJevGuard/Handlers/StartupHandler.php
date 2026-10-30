<?php

    namespace FederationJevGuard\Handlers;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\Logger;
    use FederationJevGuard\Classes\MediaSandbox;
    use FederationJevGuard\Classes\PluginOperator;
    use FederationJevGuard\Enums\DatabaseTables;
    use FederationJevGuard\Exceptions\ReviewException;
    use InvalidArgumentException;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\StartupEventHandlerInterface;
    use FederationLib\Objects\Plugin\Startup;
    use PDOException;

    class StartupHandler implements StartupEventHandlerInterface
    {
        /**
         * @inheritDoc
         * @throws DatabaseOperationException If the plugin's tables cannot be created
         */
        public static function handleStartup(Startup $startup): void
        {
            if(!Configuration::isEnabled())
            {
                Logger::log()->info('FederationJevGuard is disabled (FEDERATION_JEVGUARD_ENABLED), it does nothing');
                return;
            }

            // The plugin can't work without its tables, a failure here fails `federationlib init`
            self::initializeTables();

            try
            {
                PluginOperator::provision();
            }
            catch(ReviewException|DatabaseOperationException|InvalidArgumentException $e)
            {
                Logger::log()->error('Unable to provision the FederationJevGuard operator, the plugin is inactive until the next maintenance run repairs it: ' . $e->getMessage(), $e);
                return;
            }

            if(!PluginOperator::isActive())
            {
                Logger::log()->info('The FederationJevGuard operator is disabled, the plugin does nothing until it is enabled again');
                return;
            }

            if(!Configuration::getSystemOneConfiguration()->isConfigured())
            {
                Logger::log()->warning('FederationJevGuard has no API key for TypeSafe (FEDERATION_JEVGUARD_SYSTEMONE_API_KEY), it does nothing until one is configured or a self-hosted endpoint is used');
            }

            if(Configuration::isGdMissing())
            {
                Logger::log()->warning('PHP\'s GD extension is not installed, FederationJevGuard reviews without images until it is');
            }

            // Images are only ever decoded in the media sandbox, without it they are not processed at all
            if(Configuration::isImagesEnabled())
            {
                MediaSandbox::isAvailable();
            }

            Logger::log()->info(sprintf('FederationJevGuard reviews with %s at %s%s', Configuration::getSystemOneConfiguration()->getModel(),
                Configuration::getSystemOneConfiguration()->getEndpoint(), Configuration::isDryRun() ? ' in dry run, nothing is acted on' : ''));
        }

        /**
         * Creates the plugin's tables that don't exist yet, in the order of DatabaseTables::getOrderedTables()
         *
         * @return void
         * @throws DatabaseOperationException Thrown if a table could not be created or the SQL file of a table could not
         *                                    be read
         */
        public static function initializeTables(): void
        {
            foreach(DatabaseTables::getOrderedTables() as $sql)
            {
                if(self::tableExists($sql->getTableName()))
                {
                    continue;
                }

                Logger::log()->info("Creating table {$sql->getTableName()}");
                $sqlContent = @file_get_contents($sql->getPath());
                if ($sqlContent === false)
                {
                    throw new DatabaseOperationException("Failed to read SQL file for table $sql->name at path: {$sql->getPath()}");
                }

                try
                {
                    DatabaseConnection::getConnection()->exec($sqlContent);
                    if(!self::tableExists($sql->getTableName()))
                    {
                        throw new DatabaseOperationException("Failed to create table {$sql->getTableName()} verify if the SQL in {$sql->getPath()} is valid");
                    }

                    Logger::log()->info("Database table {$sql->getTableName()} initialized successfully.");
                }
                catch (PDOException $e)
                {
                    throw new DatabaseOperationException("Failed to execute SQL for table $sql->name: " . $e->getMessage(), 0, $e);
                }
            }
        }

        /**
         * Checks if the given table exists in the database
         *
         * @param string $table The table to check its existence
         * @return bool Returns True if the table exists, False otherwise.
         * @throws DatabaseOperationException Thrown if the tables could not be listed
         */
        private static function tableExists(string $table): bool
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare('SHOW TABLES LIKE :table');
                $stmt->bindValue(':table', $table);
                $stmt->execute();

                return $stmt->rowCount() > 0;
            }
            catch (PDOException $e)
            {
                throw new DatabaseOperationException("Failed to check if the table $table exists: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }
    }
