<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\Judges\BlacklistJudge;
    use FederationJevGuard\Classes\Judges\ClassificationJudge;
    use FederationJevGuard\Classes\Questions\BlacklistQuestions;
    use FederationJevGuard\Classes\Questions\ClassificationQuestions;
    use FederationJevGuard\Objects\SystemOne\SystemOneResponse;
    use FederationJevGuard\Tests\Helpers\Answers;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use PHPUnit\Framework\TestCase;

    /**
     * The judges decide in code what the model's answers mean, every case here is a fixed set of answers
     */
    class JudgesTest extends TestCase
    {
        private const int WEEK = 604800;
        private const int QUARTER = 7776000;

        private static function response(array $answers): SystemOneResponse
        {
            return new SystemOneResponse(['model' => 'fake-jev', 'answers' => $answers], 'jev-latest');
        }

        private static function classification(array $probabilities, ?array $reversed=null, float $signal=0.95, float $injection=0.02): SystemOneResponse
        {
            return self::response([
                ClassificationQuestions::CLASSIFICATION => Answers::choice($probabilities),
                ClassificationQuestions::CLASSIFICATION_REVERSED => Answers::choice($reversed ?? $probabilities),
                ClassificationQuestions::PROMOTION => Answers::noul($signal),
                ClassificationQuestions::PAID_OFFER => Answers::noul(min($signal, 0.1)),
                ClassificationQuestions::SCAM => Answers::noul(0.05),
                ClassificationQuestions::MALWARE => Answers::noul(0.05),
                ClassificationQuestions::ADDRESSES_REVIEWER => Answers::noul($injection),
                ClassificationQuestions::INCIDENT_TYPE => Answers::choice(['SPAM' => 0.2, 'SCAM' => 0.7, 'PHISHING' => 0.05, 'MALWARE' => 0.05]),
            ]);
        }

        public function testClearSpamIsConfident(): void
        {
            $verdict = ClassificationJudge::judge(self::classification(['NORMAL' => 0.04, 'SUSPICIOUS' => 0.08, 'MALICIOUS' => 0.88]), ClassificationJudge::REPORT_THRESHOLD);

            $this->assertTrue($verdict->isConfident());
            $this->assertSame(ClassificationFlag::MALICIOUS, $verdict->getDetail('flag'));
            $this->assertEqualsWithDelta(0.88, $verdict->getProbability(), 0.001);
            $this->assertSame(IncidentType::SCAM, $verdict->getDetail('incident_type'));
        }

        public function testBothOrdersMustAgree(): void
        {
            $verdict = ClassificationJudge::judge(self::classification(['NORMAL' => 0.1, 'SUSPICIOUS' => 0.1, 'MALICIOUS' => 0.8], ['NORMAL' => 0.6, 'SUSPICIOUS' => 0.1, 'MALICIOUS' => 0.3]), ClassificationJudge::REPORT_THRESHOLD);

            $this->assertFalse($verdict->isConfident());
            $this->assertStringContainsString('order', $verdict->getReason());
        }

        public function testSpamNeedsASignalOfSpam(): void
        {
            $verdict = ClassificationJudge::judge(self::classification(['NORMAL' => 0.05, 'SUSPICIOUS' => 0.05, 'MALICIOUS' => 0.9], signal: 0.05), ClassificationJudge::REPORT_THRESHOLD);

            $this->assertFalse($verdict->isConfident());
        }

        public function testNormalWithASignalOfSpamIsUncertain(): void
        {
            $verdict = ClassificationJudge::judge(self::classification(['NORMAL' => 0.9, 'SUSPICIOUS' => 0.05, 'MALICIOUS' => 0.05], signal: 0.9), ClassificationJudge::REPORT_THRESHOLD);

            $this->assertFalse($verdict->isConfident());
        }

        public function testContentAddressingTheReviewerIsNeverCleared(): void
        {
            $verdict = ClassificationJudge::judge(self::classification(['NORMAL' => 0.95, 'SUSPICIOUS' => 0.03, 'MALICIOUS' => 0.02], signal: 0.05, injection: 0.9), ClassificationJudge::REPORT_THRESHOLD);

            $this->assertFalse($verdict->isConfident());
            $this->assertStringContainsString('addresses', $verdict->getReason());
        }

        public function testSpamWithoutItsKindIsSuspicious(): void
        {
            $verdict = ClassificationJudge::judge(self::classification(['NORMAL' => 0.1, 'SUSPICIOUS' => 0.4, 'MALICIOUS' => 0.5], signal: 0.8), ClassificationJudge::REPORT_THRESHOLD);

            $this->assertTrue($verdict->isConfident());
            $this->assertSame(ClassificationFlag::SUSPICIOUS, $verdict->getDetail('flag'));
            $this->assertEqualsWithDelta(0.9, $verdict->getProbability(), 0.001);
        }

        public function testAGuessIsNotSuspicious(): void
        {
            $verdict = ClassificationJudge::judge(self::classification(['NORMAL' => 0.3, 'SUSPICIOUS' => 0.3, 'MALICIOUS' => 0.4], signal: 0.8), ClassificationJudge::SCAN_THRESHOLD);
            $this->assertFalse($verdict->isConfident());
        }

        public function testThresholdsScaleWithTheAction(): void
        {
            $response = self::classification(['NORMAL' => 0.8, 'SUSPICIOUS' => 0.15, 'MALICIOUS' => 0.05], signal: 0.05);

            $this->assertTrue(ClassificationJudge::judge($response, ClassificationJudge::REPORT_THRESHOLD)->isConfident());
            // Evidence nobody reported needs more
            $this->assertFalse(ClassificationJudge::judge($response, ClassificationJudge::EVIDENCE_THRESHOLD)->isConfident());

            // Spam that isn't clearly MALICIOUS for evidence is still clearly spam, it's classified SUSPICIOUS
            $verdict = ClassificationJudge::judge(self::classification(['NORMAL' => 0.05, 'SUSPICIOUS' => 0.15, 'MALICIOUS' => 0.8]), ClassificationJudge::EVIDENCE_THRESHOLD);
            $this->assertTrue($verdict->isConfident());
            $this->assertSame(ClassificationFlag::SUSPICIOUS, $verdict->getDetail('flag'));
        }

        public function testCompactAnswersAreJudgedOnTheClassificationAlone(): void
        {
            $verdict = ClassificationJudge::judge(self::response([ClassificationQuestions::CLASSIFICATION => Answers::choice(['NORMAL' => 0.9, 'SUSPICIOUS' => 0.05, 'MALICIOUS' => 0.05])]), ClassificationJudge::REPORT_THRESHOLD);

            $this->assertTrue($verdict->isConfident());
            $this->assertSame(ClassificationFlag::NORMAL, $verdict->getDetail('flag'));
            $this->assertNull($verdict->getDetail('signal'));
            $this->assertSame(IncidentType::SPAM, $verdict->getDetail('incident_type'));
        }

        public function testPrefixedQuestions(): void
        {
            $verdict = ClassificationJudge::judge(self::response(['m2_' . ClassificationQuestions::CLASSIFICATION => Answers::choice(['NORMAL' => 0.1, 'SUSPICIOUS' => 0.1, 'MALICIOUS' => 0.8])]), ClassificationJudge::SCAN_THRESHOLD, 'm2_');

            $this->assertTrue($verdict->isConfident());
            $this->assertFalse(ClassificationJudge::judge(self::response(['m2_' . ClassificationQuestions::CLASSIFICATION => Answers::choice(['NORMAL' => 1.0])]), ClassificationJudge::SCAN_THRESHOLD)->isConfident());
        }

        private static function blacklist(float $blacklist=0.9, float $repeated=0.9, float $mistaken=0.05, float $compromised=0.05, float $severity=1.0, ?float $reversed=null): SystemOneResponse
        {
            $choice = fn(float $p) => Answers::choice([BlacklistQuestions::OPTION_BLACKLIST => $p, BlacklistQuestions::OPTION_DO_NOT_BLACKLIST => 1 - $p]);
            return self::response([
                BlacklistQuestions::BLACKLIST => $choice($blacklist),
                BlacklistQuestions::BLACKLIST_REVERSED => $choice($reversed ?? $blacklist),
                BlacklistQuestions::REPEATED => Answers::noul($repeated),
                BlacklistQuestions::MISTAKEN => Answers::noul($mistaken),
                BlacklistQuestions::COMPROMISED => Answers::noul($compromised),
                BlacklistQuestions::SEVERITY => Answers::score($severity),
                BlacklistQuestions::ADDRESSES_REVIEWER => Answers::noul(0.01),
            ]);
        }

        public function testRepeatedSpamIsBlacklistedTemporarily(): void
        {
            $verdict = BlacklistJudge::judge(self::blacklist(), self::WEEK, self::QUARTER, 0);

            $this->assertTrue($verdict->isConfident());
            $this->assertSame(BlacklistQuestions::OPTION_BLACKLIST, $verdict->getVerdict());
            $this->assertSame(self::WEEK, $verdict->getDetail('duration'));
        }

        public function testRepeatOffendersAreBlacklistedLonger(): void
        {
            $this->assertSame(2 * self::WEEK, BlacklistJudge::judge(self::blacklist(), self::WEEK, self::QUARTER, 1)->getDetail('duration'));
            $this->assertSame(self::QUARTER, BlacklistJudge::judge(self::blacklist(), self::WEEK, self::QUARTER, 5)->getDetail('duration'));
            // Harmful abuse doubles it
            $this->assertSame(2 * self::WEEK, BlacklistJudge::judge(self::blacklist(severity: 2.0), self::WEEK, self::QUARTER, 0)->getDetail('duration'));
            // A taken over account only gets the first duration
            $this->assertSame(self::WEEK, BlacklistJudge::judge(self::blacklist(compromised: 0.9, severity: 2.0), self::WEEK, self::QUARTER, 3)->getDetail('duration'));
        }

        public function testLenientChecks(): void
        {
            $this->assertFalse(BlacklistJudge::judge(self::blacklist(repeated: 0.4), self::WEEK, self::QUARTER, 0)->isConfident());
            $this->assertFalse(BlacklistJudge::judge(self::blacklist(mistaken: 0.5), self::WEEK, self::QUARTER, 0)->isConfident());
            $this->assertFalse(BlacklistJudge::judge(self::blacklist(blacklist: 0.7), self::WEEK, self::QUARTER, 0)->isConfident());
            $this->assertFalse(BlacklistJudge::judge(self::blacklist(reversed: 0.3), self::WEEK, self::QUARTER, 0)->isConfident());
        }

        public function testConfidentlyDeclined(): void
        {
            $verdict = BlacklistJudge::judge(self::blacklist(blacklist: 0.1), self::WEEK, self::QUARTER, 0);

            $this->assertTrue($verdict->isConfident());
            $this->assertSame(BlacklistQuestions::OPTION_DO_NOT_BLACKLIST, $verdict->getVerdict());
        }
    }
