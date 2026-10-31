<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\PluginOperator;
    use FederationJevGuard\FederationJevGuard;
    use FederationJevGuard\Handlers\ContentScanHandler;
    use FederationJevGuard\Tests\Helpers\Answers;
    use FederationJevGuard\Tests\Helpers\FakeSystemOneClient;
    use FederationJevGuard\Tests\Helpers\PluginConfiguration;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\ContentType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\Plugin\ContentScan;
    use PHPUnit\Framework\TestCase;

    /**
     * Calls the CONTENT_SCAN handler directly with a fake System One client, nothing is sent anywhere
     */
    class ContentScanHandlerTest extends TestCase
    {
        protected function setUp(): void
        {
            PluginConfiguration::reset();
            PluginOperator::setActive(true);
        }

        protected function tearDown(): void
        {
            FederationJevGuard::setClient(null);
            PluginOperator::setActive(null);
            PluginConfiguration::reset();
        }

        private static function operator(): OperatorRecord
        {
            return new OperatorRecord(['uuid' => '01900000-0000-7000-8000-000000000009', 'name' => 'client', 'client_permissions' => true, 'created' => time(), 'updated' => time()]);
        }

        private static function scan(array $evidence, bool $authenticated=true, array $concerns=[IncidentType::SPAM, IncidentType::SCAM]): ContentScan
        {
            return new ContentScan($evidence, null, null, [], $authenticated ? self::operator() : null, null, null, $concerns);
        }

        private static function client(string $flag, ?array &$requests=null): FakeSystemOneClient
        {
            return new FakeSystemOneClient(function($state, $questions) use ($flag, &$requests)
            {
                $requests[] = $questions;
                return Answers::classification($questions, $flag);
            });
        }

        public function testSpamIsClassified(): void
        {
            FederationJevGuard::setClient(self::client('MALICIOUS', $requests));
            $scan = self::scan([new ContentInput('Earn $500 a day, message me'), new ContentInput('example.com', contentType: ContentType::HOSTNAME)]);

            ContentScanHandler::handleContentScan($scan);

            $this->assertCount(1, $requests);
            $classifications = $scan->getEvidenceClassifications(0);
            $this->assertCount(1, $classifications);
            $this->assertSame(ClassificationFlag::MALICIOUS, $classifications[0]->getClassificationFlag());
            // A hostname is left to other plugins
            $this->assertSame([], $scan->getEvidenceClassifications(1));
            // The scam signal is a detection of the effective SCAM concern, MALWARE is not a concern of this scan
            $this->assertSame([IncidentType::SCAM], array_map(fn($detection) => $detection->getConcern(), $scan->getDetections()));
        }

        public function testEveryMessageIsAskedInOneRequest(): void
        {
            FederationJevGuard::setClient(self::client('NORMAL', $requests));
            $scan = self::scan([new ContentInput('hi'), new ContentInput('how are you?')]);

            ContentScanHandler::handleContentScan($scan);

            $this->assertCount(1, $requests);
            $this->assertArrayHasKey('m0_classification', $requests[0]);
            $this->assertArrayHasKey('m1_classification', $requests[0]);
            $this->assertSame(ClassificationFlag::NORMAL, $scan->getEvidenceClassifications(1)[0]->getClassificationFlag());
        }

        public function testAnonymousScansAreNotClassified(): void
        {
            FederationJevGuard::setClient(self::client('MALICIOUS', $requests));
            $scan = self::scan([new ContentInput('Earn $500 a day')], false);

            ContentScanHandler::handleContentScan($scan);

            $this->assertNull($requests);
            $this->assertSame([], $scan->getEvidenceClassifications(0));
        }

        public function testScansWithoutSpamConcernAreNotClassified(): void
        {
            FederationJevGuard::setClient(self::client('MALICIOUS', $requests));
            ContentScanHandler::handleContentScan(self::scan([new ContentInput('Earn $500 a day')], true, [IncidentType::ADVERTISING]));

            $this->assertNull($requests);
        }

        public function testUncertainAnswersAreNotUsed(): void
        {
            FederationJevGuard::setClient(new FakeSystemOneClient(fn($state, $questions) => Answers::classification($questions, 'MALICIOUS', 0.4)));
            $scan = self::scan([new ContentInput('check my bio')]);

            ContentScanHandler::handleContentScan($scan);

            $this->assertSame([], $scan->getEvidenceClassifications(0));
        }

        public function testFailuresNeverFailTheScan(): void
        {
            FederationJevGuard::setClient(FakeSystemOneClient::unreachable());
            $scan = self::scan([new ContentInput('Earn $500 a day')]);

            ContentScanHandler::handleContentScan($scan);

            $this->assertSame([], $scan->getEvidenceClassifications(0));
        }

        public function testInactivePluginDoesNothing(): void
        {
            FederationJevGuard::setClient(self::client('MALICIOUS', $requests));
            PluginOperator::setActive(false);
            ContentScanHandler::handleContentScan(self::scan([new ContentInput('Earn $500 a day')]));

            PluginOperator::setActive(true);
            PluginConfiguration::apply(['scanning_enabled' => 'false']);
            ContentScanHandler::handleContentScan(self::scan([new ContentInput('Earn $500 a day')]));

            PluginConfiguration::apply(['systemone_endpoint' => 'https://api.typesafe.ai/v1/systemone']);
            ContentScanHandler::handleContentScan(self::scan([new ContentInput('Earn $500 a day')]));

            $this->assertNull($requests);
        }
    }
