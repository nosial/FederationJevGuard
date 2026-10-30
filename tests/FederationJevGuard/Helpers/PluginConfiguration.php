<?php

    namespace FederationJevGuard\Tests\Helpers;

    use FederationJevGuard\Classes\Configuration;

    class PluginConfiguration
    {
        private const array DEFAULTS = [
            'enabled' => 'true',
            'dry_run' => 'false',
            'concerns' => 'SPAM,SCAM,PHISHING,MALWARE',
            // Nothing listens on port 1, the unit tests can never reach TypeSafe or any other endpoint. Without an API key
            // it counts as a self-hosted endpoint, so the plugin is configured
            'systemone_endpoint' => 'http://127.0.0.1:1/v1/systemone',
            'systemone_api_key' => '',
            'systemone_model' => 'jev-latest',
            'systemone_timeout' => '30',
            'systemone_context_tokens' => '32000',
            'systemone_long_context' => 'false',
            'systemone_daily_tokens' => '0',
            'reports_enabled' => 'true',
            'reports_include_assigned' => 'false',
            'evidence_enabled' => 'true',
            'blacklist_enabled' => 'true',
            'blacklist_min_reports' => '3',
            'blacklist_duration' => '7d',
            'blacklist_max_duration' => '90d',
            'scanning_enabled' => 'true',
            'images_enabled' => 'true',
        ];

        public static function apply(array $options=[]): void
        {
            foreach(array_merge(self::DEFAULTS, $options) as $option => $value)
            {
                putenv(sprintf('FEDERATION_JEVGUARD_%s=%s', strtoupper($option), $value));
            }

            Configuration::initialize();
        }

        public static function reset(): void
        {
            self::apply();
        }
    }
