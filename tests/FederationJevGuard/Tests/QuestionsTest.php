<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\QuestionSet;
    use FederationJevGuard\Classes\Questions\BlacklistQuestions;
    use FederationJevGuard\Classes\Questions\ClassificationQuestions;
    use FederationJevGuard\Classes\Questions\ImageQuestions;
    use FederationJevGuard\Tests\Helpers\PluginConfiguration;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use PHPUnit\Framework\TestCase;

    class QuestionsTest extends TestCase
    {
        private array $files = [];

        protected function tearDown(): void
        {
            PluginConfiguration::reset();
            foreach($this->files as $file)
            {
                @unlink($file);
            }
        }

        /**
         * Writes a question file for a test, the bundled one changed by a callback
         */
        private function writeQuestions(callable $change): string
        {
            $data = json_decode(file_get_contents(QuestionSet::getBundledPath()), true);
            $data = $change($data);
            $path = tempnam(sys_get_temp_dir(), 'jevguard_questions_');
            file_put_contents($path, is_string($data) ? $data : json_encode($data));
            return $this->files[] = $path;
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
            $questions = ImageQuestions::build();
            $this->assertSame(array_merge(ImageQuestions::SPAM_FINDINGS, [ImageQuestions::ADDRESSES_REVIEWER]), array_keys($questions));
            foreach($questions as $question)
            {
                $this->assertSame('noul', $question->toArray()['type']);
                $this->assertStringContainsString('`image`', $question->toArray()['instructions']);
            }
        }

        public function testBundledQuestionsAreValid(): void
        {
            $data = json_decode(file_get_contents(QuestionSet::getBundledPath()), true);
            $this->assertSame([], QuestionSet::validate($data));
            $this->assertSame([], QuestionSet::validate($data, $data));

            $set = QuestionSet::get();
            $this->assertTrue($set->isBundled());
            $this->assertMatchesRegularExpression('/^[^+]+\+[0-9a-f]{12}$/', $set->getVersion());
        }

        public function testPlaceholdersAreFilledIn(): void
        {
            $classification = json_encode(array_map(fn($question) => $question->toArray(), ClassificationQuestions::build('messages[3]', [IncidentType::SPAM], '', false)), JSON_UNESCAPED_SLASHES);
            $blacklist = json_encode(array_map(fn($question) => $question->toArray(), BlacklistQuestions::build(21, [IncidentType::SPAM], false)));

            $this->assertStringNotContainsString('{path}', $classification);
            $this->assertStringContainsString('messages[3]', $classification);
            $this->assertStringNotContainsString('{days}', $blacklist);
            $this->assertStringContainsString('21 days', $blacklist);
        }

        public function testBlacklistOnlyJudgesSpam(): void
        {
            // "Abuse" reads as harassment, which never counts towards a blacklist
            $text = strtolower(json_encode(array_map(fn($question) => $question->toArray(), BlacklistQuestions::build(7, [], false))));
            $this->assertStringNotContainsString('spam or abuse', $text);
        }

        public function testCustomQuestionsChangeTheWording(): void
        {
            $path = $this->writeQuestions(function(array $data): array
            {
                $data['version'] = 'acme-2';
                $data['classification']['promotion']['instructions'] = 'Does `{path}` advertise anything at all?';
                $data['concerns']['SPAM'] = 'Advertising of any kind';
                return $data;
            });
            PluginConfiguration::apply(['questions_path' => $path]);

            $set = QuestionSet::get();
            $this->assertFalse($set->isBundled());
            $this->assertStringStartsWith('acme-2+custom.', $set->getVersion());

            $questions = ClassificationQuestions::build('messages', [IncidentType::SPAM], '', false);
            $this->assertSame('Does `messages` advertise anything at all?', $questions[ClassificationQuestions::PROMOTION]->toArray()['instructions']);
            $this->assertSame('Advertising of any kind', $questions[ClassificationQuestions::INCIDENT_TYPE]->toArray()['criteria']['SPAM']);
        }

        public function testInvalidCustomQuestionsFallBackToTheBundledOnes(): void
        {
            $invalid = [
                'not JSON' => fn(array $data) => '{',
                'a missing question' => function(array $data) { unset($data['blacklist']['severity']); return $data; },
                'an unknown question' => function(array $data) { $data['image']['cats'] = $data['image']['scam']; return $data; },
                'another type' => function(array $data) { $data['blacklist']['repeated_abuse']['type'] = 'choice'; return $data; },
                'other options' => function(array $data) { unset($data['classification']['classification']['options']['SUSPICIOUS']); return $data; },
                'other levels' => function(array $data) { array_pop($data['blacklist']['severity']['levels']); return $data; },
                'empty instructions' => function(array $data) { $data['image']['scam']['instructions'] = ' '; return $data; },
            ];

            foreach($invalid as $case => $change)
            {
                PluginConfiguration::apply(['questions_path' => $this->writeQuestions($change)]);
                $this->assertTrue(QuestionSet::get()->isBundled(), $case);
                $this->assertCount(8, ClassificationQuestions::build('messages', [IncidentType::SPAM], '', false), $case);
            }

            PluginConfiguration::apply(['questions_path' => '/nonexistent/questions.json']);
            $this->assertTrue(QuestionSet::get()->isBundled());
        }
    }
