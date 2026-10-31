<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\ContextBuilder;
    use FederationJevGuard\Classes\UntrustedContent;
    use FederationJevGuard\Objects\Limits;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\EvidenceRecord;
    use PHPUnit\Framework\TestCase;

    /**
     * The parts of the states that need no database
     */
    class ContextBuilderTest extends TestCase
    {
        private static function evidence(?string $flag): EvidenceRecord
        {
            return new EvidenceRecord(['uuid' => '01900000-0000-7000-8000-' . substr(hash('sha256', (string)$flag . random_int(0, PHP_INT_MAX)), 0, 12),
                'entity' => '01900000-0000-7000-8000-000000000001', 'operator' => '01900000-0000-7000-8000-000000000002',
                'confidential' => true, 'classification_flag' => $flag, 'created' => time()]);
        }

        public function testReportClassificationIsTheWorstOfItsEvidence(): void
        {
            $this->assertNull(ContextBuilder::getReportClassification([]));
            $this->assertNull(ContextBuilder::getReportClassification([self::evidence(null)]));
            $this->assertSame(ClassificationFlag::NORMAL, ContextBuilder::getReportClassification([self::evidence(null), self::evidence('NORMAL')]));
            $this->assertSame(ClassificationFlag::SUSPICIOUS, ContextBuilder::getReportClassification([self::evidence('NORMAL'), self::evidence('SUSPICIOUS')]));
            $this->assertSame(ClassificationFlag::MALICIOUS, ContextBuilder::getReportClassification([self::evidence('SUSPICIOUS'), self::evidence('MALICIOUS'), self::evidence('NORMAL')]));
        }

        public function testBotsAreRecognizedByTheirMetadata(): void
        {
            $entity = fn(?array $metadata) => new EntityRecord(['uuid' => '01900000-0000-7000-8000-000000000001', 'hash' => hash('sha256', 'x'), 'host' => 'telegram.org', 'id' => '1', 'metadata' => $metadata, 'reputation' => 0, 'created' => time()]);

            $this->assertTrue(ContextBuilder::isBot($entity(['is_bot' => true])));
            $this->assertTrue(ContextBuilder::isBot($entity(['is_bot' => 'yes'])));
            $this->assertTrue(ContextBuilder::isBot($entity(['is_bot' => 1])));
            $this->assertFalse(ContextBuilder::isBot($entity(['is_bot' => false])));
            $this->assertFalse(ContextBuilder::isBot($entity(null)));
        }

        public function testTelephoneNumbersNeverLeaveTheServer(): void
        {
            $phone = new EntityRecord(['uuid' => '01900000-0000-7000-8000-000000000001', 'hash' => hash('sha256', 'p'), 'host' => 'e164.arpa', 'id' => '15550123456', 'reputation' => 0, 'created' => time()]);
            $user = new EntityRecord(['uuid' => '01900000-0000-7000-8000-000000000002', 'hash' => hash('sha256', 'u'), 'host' => 'telegram.org', 'id' => '42', 'reputation' => -20, 'created' => time() - 3 * 86400]);

            $this->assertSame('a telephone number', ContextBuilder::address($phone));
            $this->assertStringNotContainsString('15550123456', json_encode(ContextBuilder::entitySummary($phone, new Limits())));
            $this->assertSame(['address' => '42@telegram.org', 'reputation' => -20, 'whitelisted' => false, 'first_seen_days_ago' => 3], ContextBuilder::entitySummary($user, new Limits()));
        }

        public function testActiveBlacklists(): void
        {
            $record = fn(bool $lifted, ?int $expires) => new BlacklistRecord(['uuid' => '01900000-0000-7000-9000-000000000001', 'entity' => '01900000-0000-7000-8000-000000000001',
                'operator' => '01900000-0000-7000-9000-000000000002', 'type' => 'SPAM', 'expires' => $expires, 'lifted' => $lifted, 'created' => time() - 100]);

            $this->assertTrue(ContextBuilder::isActive($record(false, null)));
            $this->assertTrue(ContextBuilder::isActive($record(false, time() + 3600)));
            $this->assertFalse(ContextBuilder::isActive($record(false, time() - 10)));
            $this->assertFalse(ContextBuilder::isActive($record(true, null)));
        }

        public function testHelpers(): void
        {
            $this->assertNull(ContextBuilder::cut('   ', 10));
            $this->assertSame('hello', ContextBuilder::cut(' hello ', 10));
            $this->assertSame('héllo…', ContextBuilder::cut('héllo wörld', 5));
            $this->assertSame(['a' => 0, 'b' => false], ContextBuilder::compact(['a' => 0, 'b' => false, 'c' => null, 'd' => '', 'e' => []]));
            $this->assertSame('2026-01-01 00:00 UTC', ContextBuilder::date(1767225600));
            $this->assertNull(ContextBuilder::date(null));
            $this->assertSame(2, ContextBuilder::daysAgo(time() - 2 * 86400 - 60));

            $metadata = ContextBuilder::metadata(['bio' => str_repeat('x', 1000), 'empty' => '', 'premium' => true], new Limits());
            $this->assertSame(ContextBuilder::MAX_METADATA_LENGTH + 1, mb_strlen($metadata['bio']));
            $this->assertArrayNotHasKey('empty', $metadata);
            $this->assertTrue($metadata['premium']);
        }

        public function testTextsAreCleaned(): void
        {
            // Untrusted text: control characters (eg; terminal escapes, NUL bytes) are removed and invalid UTF-8 replaced
            $this->assertSame("spam[31mred\nline", ContextBuilder::cut("spam\x1b[31mred\x00\nline", 100));
            $this->assertTrue(mb_check_encoding(ContextBuilder::cut("bad \xC3\x28 utf8", 100), 'UTF-8'));
            $this->assertSame("tab\tkept", UntrustedContent::sanitizeText("tab\tkept"));
        }

        public function testLimitsShrinkTextsFirst(): void
        {
            $limits = new Limits();
            $steps = 0;
            while(($smaller = $limits->shrink()) !== null)
            {
                $this->assertTrue($smaller->getTextLength() < $limits->getTextLength() || $smaller->getMaxImages() < $limits->getMaxImages() || $smaller->getMaxItems() < $limits->getMaxItems());
                if($smaller->getMaxItems() < $limits->getMaxItems())
                {
                    // Items are only left out once texts are as short as they get and no images are left
                    $this->assertSame(Limits::MIN_TEXT_LENGTH, $limits->getTextLength());
                    $this->assertSame(0, $smaller->getMaxImages());
                }

                $limits = $smaller;
                $steps++;
            }

            $this->assertSame(1, $limits->getMaxItems());
            $this->assertGreaterThan(5, $steps);
        }
    }
