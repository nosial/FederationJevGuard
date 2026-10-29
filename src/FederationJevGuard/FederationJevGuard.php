<?php

    namespace FederationJevGuard;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\PluginOperator;
    use FederationJevGuard\Classes\SystemOneClient;
    use FederationJevGuard\Interfaces\SystemOneClientInterface;

    class FederationJevGuard
    {
        private static ?SystemOneClientInterface $client = null;

        /**
         * Returns the client of the configured System One endpoint
         *
         * @return SystemOneClientInterface The client
         */
        public static function getClient(): SystemOneClientInterface
        {
            if(self::$client === null)
            {
                self::$client = new SystemOneClient();
            }

            return self::$client;
        }

        /**
         * Replaces the client of the System One endpoint, null resets it to the client of the plugin's configuration
         *
         * @param SystemOneClientInterface|null $client The client
         * @return void
         */
        public static function setClient(?SystemOneClientInterface $client): void
        {
            self::$client = $client;
        }

        /**
         * Checks if the plugin may act: it's enabled (the hard switch), the System One endpoint is configured (TypeSafe's
         * API needs an API key) and its operator exists and is enabled (the soft switch)
         *
         * @return bool True if the plugin may act
         */
        public static function isOperational(): bool
        {
            return Configuration::isEnabled() && Configuration::getSystemOneConfiguration()->isConfigured() && PluginOperator::isActive();
        }
    }
