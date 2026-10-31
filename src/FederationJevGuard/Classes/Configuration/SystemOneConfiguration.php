<?php

    namespace FederationJevGuard\Classes\Configuration;

    use FederationLib\Classes\Configuration\ConfigurationValues;

    class SystemOneConfiguration
    {
        public const string DEFAULT_ENDPOINT = 'https://api.typesafe.ai/v1/systemone';
        public const string DEFAULT_MODEL = 'jev-latest';
        public const int DEFAULT_CONTEXT_TOKENS = 32000;
        public const int COMPACT_CONTEXT_TOKENS = 8192;
        public const int MINIMUM_CONTEXT_TOKENS = 1024;

        private string $endpoint;
        private ?string $apiKey;
        private string $model;
        private int $timeout;
        private int $contextTokens;
        private bool $longContext;
        private int $dailyTokens;

        /**
         * SystemOneConfiguration constructor.
         *
         * @param array $configuration The values of the `systemone` section
         */
        public function __construct(array $configuration)
        {
            $endpoint = is_string($configuration['endpoint'] ?? null) ? trim($configuration['endpoint']) : '';
            $this->endpoint = $endpoint !== '' ? rtrim($endpoint, '/') : self::DEFAULT_ENDPOINT;

            $apiKey = is_string($configuration['api_key'] ?? null) ? trim($configuration['api_key']) : '';
            $this->apiKey = $apiKey !== '' ? $apiKey : null;

            $model = is_string($configuration['model'] ?? null) ? trim($configuration['model']) : '';
            $this->model = $model !== '' ? $model : self::DEFAULT_MODEL;

            $this->timeout = max(1, (int)($configuration['timeout'] ?? 30));
            $this->contextTokens = max(self::MINIMUM_CONTEXT_TOKENS, (int)($configuration['context_tokens'] ?? self::DEFAULT_CONTEXT_TOKENS));
            $this->longContext = ConfigurationValues::parseBool($configuration['long_context'] ?? null, false);
            $this->dailyTokens = max(0, (int)($configuration['daily_tokens'] ?? 0));
        }

        /**
         * Returns the System One endpoint the requests are sent to, TypeSafe's by default or a self-hosted one (eg;
         * Ollama's /v1/systemone)
         *
         * @return string The endpoint URL
         */
        public function getEndpoint(): string
        {
            return $this->endpoint;
        }

        /**
         * Returns True if the requests go to TypeSafe's API
         *
         * @return bool True for TypeSafe's API
         */
        public function isTypeSafe(): bool
        {
            return strtolower((string)parse_url($this->endpoint, PHP_URL_HOST)) === 'api.typesafe.ai';
        }

        /**
         * Returns the API key sent as a bearer token, required by TypeSafe's API and optional for a self-hosted endpoint
         *
         * @return string|null The API key, null if none is configured
         */
        public function getApiKey(): ?string
        {
            return $this->apiKey;
        }

        /**
         * Returns True if the plugin can make requests: TypeSafe's API needs an API key, a self-hosted endpoint may not
         *
         * @return bool True if configured
         */
        public function isConfigured(): bool
        {
            return !$this->isTypeSafe() || $this->apiKey !== null;
        }

        /**
         * Returns the model the requests ask for, eg; jev-latest or a versioned ID such as jev-1.14.0
         *
         * @return string The model name or alias
         */
        public function getModel(): string
        {
            return $this->model;
        }

        /**
         * Returns the timeout of a request made during maintenance or a review, in seconds. The requests of content
         * scans use a shorter timeout of their own
         *
         * @return int The timeout in seconds
         */
        public function getTimeout(): int
        {
            return $this->timeout;
        }

        /**
         * Returns the context of a single question of the model in tokens, the plugin fits everything it sends with a
         * question into it. Small self-hosted models need a much smaller value than TypeSafe's Jev models
         *
         * @return int The context in tokens
         */
        public function getContextTokens(): int
        {
            return $this->contextTokens;
        }

        /**
         * Returns True if the model's context is too small for every question, only the essential ones are asked: the
         * classification without its reversed order and signals, and the blacklist decision with its pattern check
         *
         * @return bool True for compact questions
         */
        public function isCompact(): bool
        {
            return $this->contextTokens < self::COMPACT_CONTEXT_TOKENS;
        }

        /**
         * Returns True if the requests ask TypeSafe for its long context (TypeSafe-Danger-Zone-Long-Context)
         *
         * @return bool True for long context
         */
        public function isLongContext(): bool
        {
            return $this->longContext;
        }

        /**
         * Returns the input tokens the plugin may use per day (UTC), 0 for no limit
         *
         * @return int The input tokens per day
         */
        public function getDailyTokens(): int
        {
            return $this->dailyTokens;
        }
    }
