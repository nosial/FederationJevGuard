<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\Questions\BlacklistQuestions;
    use FederationJevGuard\Classes\Questions\ClassificationQuestions;
    use FederationJevGuard\Classes\Questions\ImageQuestions;
    use FederationJevGuard\Tests\Helpers\PluginConfiguration;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use PHPUnit\Framework\TestCase;

    class QuestionsTest extends TestCase
    {
        protected function tearDown(): void
        {
            PluginConfiguration::reset();
        }

        public function testClassificationQuestions(): void
        {
            $questions = ClassificationQuestions::build('messages', [IncidentType::SPAM, IncidentType::MALWARE], '', false);

            $this->assertSame([
                ClassificationQuestions::CLASSIFICATION, ClassificationQuestions::CLASSIFICATION_REVERSED, ClassificationQuestions::PROMOTION,
                ClassificationQuestions::PAID_OFFER, ClassificationQuestions::SCAM, ClassificationQuestions::MALWARE,
                ClassificationQuestions::ADDRESSES_REVIEWER, ClassificationQuestions::INCIDENT_TYPE,
            ], array_keys($questions));

            // The options are FederationLib's classification flags, in both orders
            $flags = array_map(fn(ClassificationFlag $flag) => $flag->value, ClassificationFlag::cases());
            $this->assertEqualsCanonicalizing($flags, array_keys($questions[ClassificationQuestions::CLASSIFICATION]->toArray()['criteria']));
            $this->assertSame(array_reverse(array_keys($questions[ClassificationQuestions::CLASSIFICATION]->toArray()['criteria'])),
                array_keys($questions[ClassificationQuestions::CLASSIFICATION_REVERSED]->toArray()['criteria']));
            $this->assertSame(['SPAM', 'MALWARE'], array_keys($questions[ClassificationQuestions::INCIDENT_TYPE]->toArray()['criteria']));

            // Questions point at the content by path
            $this->assertStringContainsString('`messages`', json_encode($questions[ClassificationQuestions::PROMOTION]->toArray()));
        }

        public function testPrefixedQuestionsDontCollide(): void
        {
            $questions = ClassificationQuestions::build('messages[0]', [], 'm0_', false) + ClassificationQuestions::build('messages[1]', [], 'm1_', false);

            $this->assertCount(14, $questions);
            $this->assertStringContainsString('`messages[1]`', json_encode($questions['m1_' . ClassificationQuestions::PROMOTION]->toArray()));
        }

        public function testCompactQuestionsFollowTheContext(): void
        {
            PluginConfiguration::apply(['systemone_context_tokens' => '2048']);
            $this->assertSame([ClassificationQuestions::CLASSIFICATION], array_keys(ClassificationQuestions::build('messages', [IncidentType::SPAM])));
            $this->assertSame([BlacklistQuestions::BLACKLIST, BlacklistQuestions::REPEATED], array_keys(BlacklistQuestions::build(7, [IncidentType::SPAM])));

            PluginConfiguration::reset();
            $this->assertCount(8, ClassificationQuestions::build('messages', [IncidentType::SPAM]));
            $this->assertCount(8, BlacklistQuestions::build(7, [IncidentType::SPAM]));
        }

        public function testBlacklistQuestionNamesTheDuration(): void
        {
            $questions = BlacklistQuestions::build(14, [IncidentType::SPAM], false);

            $this->assertStringContainsString('14 days', json_encode($questions[BlacklistQuestions::BLACKLIST]->toArray()));
            $this->assertSame(3, count($questions[BlacklistQuestions::SEVERITY]->toArray()['criteria']));
        }

        public function testImageQuestionsAreYesNo(): void
        {
            foreach(ImageQuestions::build() as $question)
            {
                $this->assertSame('noul', $question->toArray()['type']);
                $this->assertStringContainsString('`image`', $question->toArray()['instructions']);
            }
        }
    }
