<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\Configuration\BlacklistConfiguration;
    use FederationJevGuard\Classes\Configuration\SystemOneConfiguration;
    use FederationJevGuard\Tests\Helpers\PluginConfiguration;
    use FederationLib\Enums\IncidentType;
    use PHPUnit\Framework\TestCase;

    class ConfigurationTest extends TestCase
    {
        protected function tearDown(): void
        {
            PluginConfiguration::reset();
        }

        public function testTypeSafeIsTheDefaultEndpoint(): void
        {
            $configuration = new SystemOneConfiguration([]);

            $this->assertSame('https://api.typesafe.ai/v1/systemone', $configuration->getEndpoint());
            $this->assertTrue($configuration->isTypeSafe());
            $this->assertSame('jev-latest', $configuration->getModel());
            $this->assertSame(32000, $configuration->getContextTokens());
            $this->assertFalse($configuration->isCompact());
            $this->assertFalse($configuration->isLongContext());
            $this->assertSame(0, $configuration->getDailyTokens());
        }

        public function testTypeSafeNeedsAnApiKey(): void
        {
            $this->assertFalse(new SystemOneConfiguration([])->isConfigured());
            $this->assertTrue(new SystemOneConfiguration(['api_key' => 'ts-key'])->isConfigured());
            $this->assertSame('ts-key', new SystemOneConfiguration(['api_key' => ' ts-key '])->getApiKey());
        }

        public function testSelfHostedEndpointNeedsNoApiKey(): void
        {
            $configuration = new SystemOneConfiguration(['endpoint' => 'http://ollama:11434/v1/systemone/', 'model' => 'tev1:4b', 'context_tokens' => 2048]);

            $this->assertSame('http://ollama:11434/v1/systemone', $configuration->getEndpoint());
            $this->assertFalse($configuration->isTypeSafe());
            $this->assertTrue($configuration->isConfigured());
            $this->assertNull($configuration->getApiKey());
            $this->assertSame('tev1:4b', $configuration->getModel());
            // A small model is only asked the essential questions
            $this->assertTrue($configuration->isCompact());
        }

        public function testContextHasAFloor(): void
        {
            $this->assertSame(SystemOneConfiguration::MINIMUM_CONTEXT_TOKENS, new SystemOneConfiguration(['context_tokens' => 10])->getContextTokens());
        }

        public function testBlacklistsAreAlwaysTemporary(): void
        {
            $configuration = new BlacklistConfiguration(['duration' => 'never', 'max_duration' => '1d']);

            // "never" can't make a permanent blacklist record, the duration falls back to its default
            $this->assertSame(604800, $configuration->getDuration());
            $this->assertSame(604800, $configuration->getMaxDuration());
            $this->assertSame(3600, new BlacklistConfiguration(['duration' => '1m'])->getDuration());
        }

        public function testDefaults(): void
        {
            PluginConfiguration::reset();

            $this->assertTrue(Configuration::isEnabled());
            $this->assertFalse(Configuration::isDryRun());
            $this->assertSame([IncidentType::SPAM, IncidentType::SCAM, IncidentType::PHISHING, IncidentType::MALWARE], Configuration::getConcerns());
            $this->assertTrue(Configuration::getReviewConfiguration()->isReportsEnabled());
            $this->assertFalse(Configuration::getReviewConfiguration()->isIncludeAssigned());
            $this->assertTrue(Configuration::getReviewConfiguration()->isEvidenceEnabled());
            $this->assertTrue(Configuration::getBlacklistConfiguration()->isEnabled());
            $this->assertSame(3, Configuration::getBlacklistConfiguration()->getMinReports());
            $this->assertTrue(Configuration::isScanningEnabled());
            $this->assertSame(extension_loaded('gd'), Configuration::isImagesEnabled());
            $this->assertSame(!extension_loaded('gd'), Configuration::isGdMissing());
        }

        public function testConcernsAreLimitedToTheSupportedOnes(): void
        {
            PluginConfiguration::apply(['concerns' => 'spam, advertising, MALWARE, nonsense']);
            $this->assertSame([IncidentType::SPAM, IncidentType::MALWARE], Configuration::getConcerns());

            PluginConfiguration::apply(['concerns' => 'ADVERTISING']);
            $this->assertSame([IncidentType::SPAM, IncidentType::SCAM, IncidentType::PHISHING, IncidentType::MALWARE], Configuration::getConcerns());
        }
    }
