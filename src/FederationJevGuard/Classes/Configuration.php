<?php

    namespace FederationJevGuard\Classes;

    use FederationJevGuard\Classes\Configuration\BlacklistConfiguration;
    use FederationJevGuard\Classes\Configuration\ReviewConfiguration;
    use FederationJevGuard\Classes\Configuration\SystemOneConfiguration;
    use FederationLib\Classes\Configuration\ConfigurationValues;
    use FederationLib\Enums\IncidentType;

    class Configuration
    {
        public const array DEFAULT_CONCERNS = ['SPAM', 'SCAM', 'PHISHING', 'MALWARE'];
        public const array SUPPORTED_CONCERNS = ['SPAM', 'SCAM', 'PHISHING', 'MALWARE', 'SERVICE_ABUSE', 'ILLEGAL_CONTENT'];

        private static ?\ConfigLib\Configuration $configuration = null;
        private static ?SystemOneConfiguration $systemOneConfiguration = null;
        private static ?ReviewConfiguration $reviewConfiguration = null;
        private static ?BlacklistConfiguration $blacklistConfiguration = null;
        private static bool $enabled = true;
        private static bool $dryRun = false;
        private static bool $scanningEnabled = true;
        private static bool $imagesEnabled = true;
        /** @var IncidentType[] */
        private static array $concerns = [];

        /**
         * Initializes the configuration with its default values, every value can also be set with its environment
         * variable
         *
         * @return void
         */
        public static function initialize(): void
        {
            self::$configuration = new \ConfigLib\Configuration('federation_jevguard');

            // The hard switch: while disabled the plugin does nothing at all. Disabling the FederationJevGuard operator
            // is the soft switch, see PluginOperator::isActive()
            self::$configuration->setDefault('enabled', true, 'FEDERATION_JEVGUARD_ENABLED');
            // Decide without acting: decisions are recorded as PREVIEWED, nothing is closed, classified or blacklisted
            self::$configuration->setDefault('dry_run', false, 'FEDERATION_JEVGUARD_DRY_RUN');
            // The kinds of abuse the model judges, the incident types of the plugin's blacklist records
            self::$configuration->setDefault('concerns', implode(',', self::DEFAULT_CONCERNS), 'FEDERATION_JEVGUARD_CONCERNS');

            // The System One endpoint: TypeSafe's API by default, or a self-hosted one (eg; Ollama's /v1/systemone)
            self::$configuration->setDefault('systemone.endpoint', SystemOneConfiguration::DEFAULT_ENDPOINT, 'FEDERATION_JEVGUARD_SYSTEMONE_ENDPOINT');
            self::$configuration->setDefault('systemone.api_key', null, 'FEDERATION_JEVGUARD_SYSTEMONE_API_KEY');
            self::$configuration->setDefault('systemone.model', SystemOneConfiguration::DEFAULT_MODEL, 'FEDERATION_JEVGUARD_SYSTEMONE_MODEL');
            self::$configuration->setDefault('systemone.timeout', 30, 'FEDERATION_JEVGUARD_SYSTEMONE_TIMEOUT');
            self::$configuration->setDefault('systemone.context_tokens', SystemOneConfiguration::DEFAULT_CONTEXT_TOKENS, 'FEDERATION_JEVGUARD_SYSTEMONE_CONTEXT_TOKENS');
            self::$configuration->setDefault('systemone.long_context', false, 'FEDERATION_JEVGUARD_SYSTEMONE_LONG_CONTEXT');
            self::$configuration->setDefault('systemone.daily_tokens', 0, 'FEDERATION_JEVGUARD_SYSTEMONE_DAILY_TOKENS');

            // What the plugin reviews
            self::$configuration->setDefault('reports.enabled', true, 'FEDERATION_JEVGUARD_REPORTS_ENABLED');
            self::$configuration->setDefault('reports.include_assigned', false, 'FEDERATION_JEVGUARD_REPORTS_INCLUDE_ASSIGNED');
            self::$configuration->setDefault('evidence.enabled', true, 'FEDERATION_JEVGUARD_EVIDENCE_ENABLED');
            self::$configuration->setDefault('blacklist.enabled', true, 'FEDERATION_JEVGUARD_BLACKLIST_ENABLED');
            self::$configuration->setDefault('blacklist.min_reports', 3, 'FEDERATION_JEVGUARD_BLACKLIST_MIN_REPORTS');
            self::$configuration->setDefault('blacklist.duration', '7d', 'FEDERATION_JEVGUARD_BLACKLIST_DURATION');
            self::$configuration->setDefault('blacklist.max_duration', '90d', 'FEDERATION_JEVGUARD_BLACKLIST_MAX_DURATION');
            self::$configuration->setDefault('scanning.enabled', true, 'FEDERATION_JEVGUARD_SCANNING_ENABLED');
            self::$configuration->setDefault('images.enabled', true, 'FEDERATION_JEVGUARD_IMAGES_ENABLED');

            // Only save if the configuration file does not exist or we're in CLI mode
            if(!file_exists(self::$configuration->getPath()) || php_sapi_name() === 'cli')
            {
                self::$configuration->save();
            }

            self::$enabled = ConfigurationValues::parseBool(self::$configuration->get('enabled'), true);
            self::$dryRun = ConfigurationValues::parseBool(self::$configuration->get('dry_run'), false);
            self::$concerns = array_map(fn(string $concern) => IncidentType::from($concern),
                ConfigurationValues::parseList(self::$configuration->get('concerns'), self::SUPPORTED_CONCERNS, self::DEFAULT_CONCERNS) ?: self::DEFAULT_CONCERNS);
            self::$systemOneConfiguration = new SystemOneConfiguration(self::$configuration->get('systemone') ?? []);
            self::$reviewConfiguration = new ReviewConfiguration(self::$configuration->get('reports') ?? [], self::$configuration->get('evidence') ?? []);
            self::$blacklistConfiguration = new BlacklistConfiguration(self::$configuration->get('blacklist') ?? []);
            self::$scanningEnabled = ConfigurationValues::parseBool(self::$configuration->get('scanning')['enabled'] ?? null, true);
            self::$imagesEnabled = ConfigurationValues::parseBool(self::$configuration->get('images')['enabled'] ?? null, true);
        }

        /**
         * Returns True if the plugin is enabled, while disabled (the hard switch) it does nothing at all
         *
         * @return bool True if enabled
         */
        public static function isEnabled(): bool
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$enabled;
        }

        /**
         * Returns True if the plugin only records its decisions without acting on them
         *
         * @return bool True in dry run
         */
        public static function isDryRun(): bool
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$dryRun;
        }

        /**
         * Returns the concerns the model judges, in the configured order
         *
         * @return IncidentType[] The concerns
         */
        public static function getConcerns(): array
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$concerns;
        }

        /**
         * Returns True if the messages of authenticated content scans are classified
         *
         * @return bool True if enabled
         */
        public static function isScanningEnabled(): bool
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$scanningEnabled;
        }

        /**
         * Returns True if image attachments are given to the model. Images need PHP's GD extension and proc_open() to be
         * prepared in the media sandbox (see MediaSandbox), and a System One endpoint that accepts images
         *
         * @return bool True if enabled and GD is available
         */
        public static function isImagesEnabled(): bool
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$imagesEnabled && extension_loaded('gd') && function_exists('proc_open');
        }

        /**
         * Returns True if images are enabled in the configuration but can't be used because GD is missing
         *
         * @return bool True if GD is missing
         */
        public static function isGdMissing(): bool
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$imagesEnabled && !extension_loaded('gd');
        }

        /**
         * Get the System One configuration: the endpoint, the model and its limits.
         *
         * @return SystemOneConfiguration
         */
        public static function getSystemOneConfiguration(): SystemOneConfiguration
        {
            if(self::$systemOneConfiguration === null)
            {
                self::initialize();
            }

            return self::$systemOneConfiguration;
        }

        /**
         * Get the configuration of the report and evidence reviews.
         *
         * @return ReviewConfiguration
         */
        public static function getReviewConfiguration(): ReviewConfiguration
        {
            if(self::$reviewConfiguration === null)
            {
                self::initialize();
            }

            return self::$reviewConfiguration;
        }

        /**
         * Get the blacklist configuration.
         *
         * @return BlacklistConfiguration
         */
        public static function getBlacklistConfiguration(): BlacklistConfiguration
        {
            if(self::$blacklistConfiguration === null)
            {
                self::initialize();
            }

            return self::$blacklistConfiguration;
        }
    }
