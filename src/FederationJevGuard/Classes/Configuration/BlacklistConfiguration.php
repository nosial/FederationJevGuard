<?php

    namespace FederationJevGuard\Classes\Configuration;

    use FederationLib\Classes\Configuration\ConfigurationValues;

    class BlacklistConfiguration
    {
        public const int MAX_REPORTS = 30;
        public const int CANDIDATE_REPUTATION = -100;

        private bool $enabled;
        private int $minReports;
        private int $duration;
        private int $maxDuration;

        /**
         * BlacklistConfiguration constructor.
         *
         * @param array $configuration The values of the `blacklist` section
         */
        public function __construct(array $configuration)
        {
            $this->enabled = ConfigurationValues::parseBool($configuration['enabled'] ?? null, true);
            $this->minReports = max(1, (int)($configuration['min_reports'] ?? 3));

            // Always temporary, an entity is never blacklisted permanently by the plugin
            $this->duration = max(3600, ConfigurationValues::parseDuration($configuration['duration'] ?? '7d', 604800) ?? 604800);
            $this->maxDuration = max($this->duration, ConfigurationValues::parseDuration($configuration['max_duration'] ?? '90d', 7776000) ?? 7776000);
        }

        /**
         * Returns True if the plugin blacklists entities the model is confident repeatedly abuse
         *
         * @return bool True if enabled
         */
        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        /**
         * Returns the closed spam reports an entity needs before the model is asked about it
         *
         * @return int The number of reports
         */
        public function getMinReports(): int
        {
            return $this->minReports;
        }

        /**
         * Returns how long a first blacklist record lasts, in seconds. A repeat offender's lasts twice as long as its
         * previous one, up to getMaxDuration()
         *
         * @return int The duration in seconds
         */
        public function getDuration(): int
        {
            return $this->duration;
        }

        /**
         * Returns the longest blacklist record the plugin creates, in seconds
         *
         * @return int The duration in seconds
         */
        public function getMaxDuration(): int
        {
            return $this->maxDuration;
        }
    }
