<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\FederationClient;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\OperatorRecord;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * Tests the plugin installed in the test environment's FederationLib server (make test-env). The plugin uses the
     * mock System One endpoint (tests/mock-systemone), these tests never contact TypeSafe. The mock decides by a marker
     * in the state: JEVGUARD_TEST_SPAM is clearly spam, JEVGUARD_TEST_NORMAL is not, anything else leaves it unsure.
     * The plugin has no routes, the reviews are made by running `federationlib maintenance` in the server's container
     * (JEVGUARD_MAINTENANCE_COMMAND) and their outcome is read from the records through FederationLib's own routes
     */
    #[Group('integration')]
    class FederationServerTest extends TestCase
    {
        private FederationClient $client;

        protected function setUp(): void
        {
            $this->client = new FederationClient(self::getServerEndpoint(), getenv('SERVER_ACCESS_TOKEN') ?: 'abcdefghijklmnopqrstuvwxyz123456');
            self::mockRequest('DELETE', '/requests');
        }

        private static function getServerEndpoint(): string
        {
            return rtrim(getenv('SERVER_ENDPOINT') ?: 'http://172.17.0.1:7000', '/');
        }

        private static function mockRequest(string $method, string $path): mixed
        {
            $curl = curl_init(rtrim(getenv('MOCK_SYSTEMONE_ENDPOINT') ?: 'http://172.17.0.1:7080', '/') . $path);
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            $response = curl_exec($curl);
            if($response === false)
            {
                self::fail('The mock System One endpoint is unreachable: ' . curl_error($curl));
            }

            return json_decode((string)$response, true);
        }

        /**
         * Runs a maintenance run of the server, which queues everything not reviewed yet and reviews the queue
         */
        private static function maintenance(): void
        {
            $command = getenv('JEVGUARD_MAINTENANCE_COMMAND')
                ?: sprintf('docker compose -f %s exec -T app federationlib maintenance', escapeshellarg(dirname(__DIR__, 3) . '/docker-compose.yml'));

            exec($command . ' 2>&1', $output, $code);
            if($code !== 0)
            {
                self::fail(sprintf("The maintenance run failed with exit code %d:\n%s", $code, implode("\n", $output)));
            }
        }

        /**
         * Returns the plugin's operator
         */
        private function operator(): OperatorRecord
        {
            for($page = 1; count($operators = $this->client->listOperators($page)) > 0; $page++)
            {
                foreach($operators as $operator)
                {
                    if($operator->getName() === 'FederationJevGuard')
                    {
                        return $operator;
                    }
                }
            }

            self::fail('The plugin\'s operator does not exist');
        }

        /**
         * Pushes a new entity, the reserved .invalid domain is never looked up
         */
        private function entity(): string
        {
            $id = 'jevguard' . bin2hex(random_bytes(6));
            $this->client->pushEntity('jevguard.invalid', $id);
            return $id . '@jevguard.invalid';
        }

        private function report(string $entity, string $text): string
        {
            return $this->client->submitReport($entity, new ContentInput($text), IncidentType::SPAM, 'Reported by the FederationJevGuard tests')->getReport()->getUuid();
        }

        public function testOperatorIsProvisioned(): void
        {
            $operator = $this->operator();

            $this->assertFalse($operator->isDisabled());
            $this->assertTrue($operator->hasManagementPermissions());
        }

        public function testSpamReportIsClosed(): void
        {
            $report = $this->report($this->entity(), 'JEVGUARD_TEST_SPAM Earn $500 a day from home, message me');

            self::maintenance();

            $this->assertFalse($this->client->getReport($report)->isOpened());

            // The evidence is classified the way an operator closing the report classifies it
            $evidence = $this->client->listReportEvidenceRecords($report, includeConfidential: true);
            $this->assertSame(ClassificationFlag::MALICIOUS, $evidence[0]->getClassificationFlag());

            // Every question was asked in one request, without an API key (the mock is a self-hosted endpoint). Only the
            // classification requests are looked at, the maintenance run may have reviewed other subjects too
            $requests = array_values(array_filter(self::mockRequest('GET', '/requests'), fn(array $request) => in_array('classification', $request['questions'], true)));
            $this->assertNotEmpty($requests);
            foreach($requests as $request)
            {
                $this->assertSame('jev-latest', $request['model']);
                $this->assertContains('classification_reversed', $request['questions']);
                $this->assertContains('addresses_reviewer', $request['questions']);
                $this->assertFalse($request['authorization']);
            }
        }

        public function testNormalReportIsClosedAsNormal(): void
        {
            $report = $this->report($this->entity(), 'JEVGUARD_TEST_NORMAL hi everyone, anyone from Berlin?');

            self::maintenance();

            $this->assertFalse($this->client->getReport($report)->isOpened());
        }

        public function testUncertainReportIsLeftOpen(): void
        {
            $report = $this->report($this->entity(), 'check my bio');

            self::maintenance();

            $this->assertTrue($this->client->getReport($report)->isOpened());
            $this->assertNull($this->client->getReport($report)->getAssignedOperator());
        }

        public function testStandaloneEvidenceIsClassified(): void
        {
            $evidence = $this->client->submitEvidence($this->entity(), 'JEVGUARD_TEST_SPAM Escort service available, see my channel');

            self::maintenance();

            $this->assertSame(ClassificationFlag::MALICIOUS, $this->client->getEvidenceRecord($evidence)->getClassificationFlag());
        }

        public function testRepeatedSpamIsBlacklistedTemporarily(): void
        {
            $entity = $this->entity();

            // Not enough closed spam reports yet. The first run closes the report, the second reviews the entities of
            // recently closed reports
            $this->report($entity, 'JEVGUARD_TEST_SPAM Earn money fast, join my channel');
            self::maintenance();
            self::maintenance();
            $this->assertSame([], $this->client->listEntityBlacklistRecords($entity));

            for($i = 0; $i < 2; $i++)
            {
                $this->report($entity, sprintf('JEVGUARD_TEST_SPAM Free signals %d, message me on Telegram', $i));
            }

            self::maintenance();
            self::maintenance();

            $blacklists = $this->client->listEntityBlacklistRecords($entity);
            $this->assertCount(1, $blacklists);
            // Never permanent, and by the plugin's operator
            $this->assertNotNull($blacklists[0]->getExpires());
            $this->assertSame($this->operator()->getUuid(), $blacklists[0]->getOperatorUuid());

            // The decision is shown with the entity
            $this->assertSame('BLACKLIST', $this->client->queryEntity($entity)->getEntityRecord()->getMetadata()['jevguard_verdict'] ?? null);
        }

        public function testImagesAreJudged(): void
        {
            if(!extension_loaded('gd'))
            {
                $this->markTestSkipped('PHP\'s GD extension is needed to create the test image');
            }

            $image = imagecreatetruecolor(400, 300);
            imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
            $path = tempnam(sys_get_temp_dir(), 'jevguard') . '.png';
            imagepng($image, $path);

            try
            {
                $evidence = $this->client->submitEvidence($this->entity(), 'JEVGUARD_TEST_SPAM see the picture');
                $this->client->uploadFileAttachment($evidence, $path, 'flyer.png');
            }
            finally
            {
                @unlink($path);
            }

            self::maintenance();

            $images = array_values(array_filter(self::mockRequest('GET', '/requests'), fn(array $request) => $request['images'] > 0));
            $this->assertNotEmpty($images);
            $this->assertTrue($images[0]['images_header']);
            $this->assertSame(1, $images[0]['images']);

            // The same image is only judged once
            self::mockRequest('DELETE', '/requests');
            self::maintenance();
            $this->assertSame([], array_values(array_filter(self::mockRequest('GET', '/requests'), fn(array $request) => $request['images'] > 0)));
        }

        public function testScansAreClassified(): void
        {
            $scan = $this->client->scanContent(new ContentInput('JEVGUARD_TEST_SPAM Claim your free crypto prize, message me'), $this->entity(), concerns: [IncidentType::SPAM, IncidentType::SCAM]);

            $sources = array_map(fn($detection) => $detection->getSource(), $scan->getDetections());
            $this->assertContains('jevguard', $sources);
            $this->assertSame(ClassificationFlag::MALICIOUS, $scan->getClassification()?->getClassificationFlag());
        }
    }
