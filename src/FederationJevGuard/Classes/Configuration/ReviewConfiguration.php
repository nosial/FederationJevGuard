<?php

    namespace FederationJevGuard\Classes\Configuration;

    use FederationLib\Classes\Configuration\ConfigurationValues;

    class ReviewConfiguration
    {
        public const int MAINTENANCE_SECONDS = 1500;
        public const int MAINTENANCE_BATCH = 500;
        public const int MAX_ATTEMPTS = 8;
        public const int SETTLE_DELAY = 30;
        public const int DECISION_RETENTION = 31536000;

        private bool $reportsEnabled;
        private bool $includeAssigned;
        private bool $evidenceEnabled;

        /**
         * ReviewConfiguration constructor.
         *
         * @param array $reports The values of the `reports` section
         * @param array $evidence The values of the `evidence` section
         */
        public function __construct(array $reports, array $evidence)
        {
            $this->reportsEnabled = ConfigurationValues::parseBool($reports['enabled'] ?? null, true);
            $this->includeAssigned = ConfigurationValues::parseBool($reports['include_assigned'] ?? null, false);
            $this->evidenceEnabled = ConfigurationValues::parseBool($evidence['enabled'] ?? null, true);
        }

        /**
         * Returns True if the plugin closes opened reports it's confident about
         *
         * @return bool True if enabled
         */
        public function isReportsEnabled(): bool
        {
            return $this->reportsEnabled;
        }

        /**
         * Returns True if the plugin also takes over reports assigned to operators that are not bots, by default it
         * only reviews unassigned reports and reports assigned to bots or itself
         *
         * @return bool True to include reports assigned to people
         */
        public function isIncludeAssigned(): bool
        {
            return $this->includeAssigned;
        }

        /**
         * Returns True if the plugin classifies unclassified evidence that belongs to no report
         *
         * @return bool True if enabled
         */
        public function isEvidenceEnabled(): bool
        {
            return $this->evidenceEnabled;
        }
    }
