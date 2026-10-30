<?php

    namespace FederationJevGuard\Enums;

    enum DatabaseTables : string
    {
        case QUEUE = 'jevguard_queue.sql';
        case DECISIONS = 'jevguard_decisions.sql';
        case IMAGES = 'jevguard_images.sql';
        case USAGE = 'jevguard_usage.sql';

        /**
         * Returns the full path to the SQL file for the database table.
         *
         * @return string The full path to the SQL file.
         */
        public function getPath(): string
        {
            return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Classes' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . $this->value;
        }

        /**
         * Returns the name of the database table.
         *
         * @return string The name of the database table.
         */
        public function getTableName(): string
        {
            return match($this)
            {
                self::QUEUE => 'jevguard_queue',
                self::DECISIONS => 'jevguard_decisions',
                self::IMAGES => 'jevguard_images',
                self::USAGE => 'jevguard_usage'
            };
        }

        /**
         * Returns an array of DatabaseTables in the order they should be processed or created.
         *
         * @return DatabaseTables[]
         */
        public static function getOrderedTables(): array
        {
            return [
                self::QUEUE,
                self::DECISIONS,
                self::IMAGES,
                self::USAGE
            ];
        }
    }
