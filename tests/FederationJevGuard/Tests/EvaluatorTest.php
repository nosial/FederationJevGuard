<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\Configuration\SystemOneConfiguration;
    use FederationJevGuard\Classes\Ratelimit;
    use FederationJevGuard\Classes\Evaluator;
    use FederationJevGuard\Classes\ImageCollector;
    use FederationJevGuard\Classes\Questions\ClassificationQuestions;
    use FederationJevGuard\Exceptions\BudgetExceededException;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\FederationJevGuard;
    use FederationJevGuard\Objects\Limits;
    use FederationJevGuard\Tests\Helpers\Answers;
    use FederationJevGuard\Tests\Helpers\FakeSystemOneClient;
    use FederationJevGuard\Tests\Helpers\PluginConfiguration;
    use PHPUnit\Framework\TestCase;

    /**
     * The usage of the endpoint is recorded in the database, which the unit tests don't have: recording it fails and is
     * only logged
     */
    class EvaluatorTest extends TestCase
    {
        protected function setUp(): void
        {
            PluginConfiguration::reset();
            Ratelimit::clear(Evaluator::getRateLimitKey(Configuration::getSystemOneConfiguration()));
        }

        protected function tearDown(): void
        {
            Ratelimit::clear(Evaluator::getRateLimitKey(Configuration::getSystemOneConfiguration()));
            Ratelimit::clear('budget:1000');
            FederationJevGuard::setClient(null);
            PluginConfiguration::reset();
        }

        private static function build(int $messages): callable
        {
            return fn(Limits $limits, ImageCollector $images) => ['messages' => array_slice(array_map(
                fn(int $i) => ['text' => mb_substr(str_repeat("message $i ", 600), 0, $limits->getTextLength())], range(1, $messages)), 0, $limits->getMaxItems())];
        }

        public function testStateIsShrunkToTheBudget(): void
        {
            [$state] = Evaluator::fit(self::build(40), 20000);

            $this->assertLessThanOrEqual(20000, strlen(json_encode($state)));
            $this->assertNotEmpty($state['messages']);
        }

        public function testSmallStateIsKept(): void
        {
            [$state] = Evaluator::fit(self::build(2), 100000);

            $this->assertCount(2, $state['messages']);
            $this->assertSame(Limits::TEXT_LENGTH, mb_strlen($state['messages'][0]['text']));
        }

        public function testBudgetFollowsTheEndpoint(): void
        {
            $questions = ClassificationQuestions::build('messages', [], '', false);
            $typeSafe = Evaluator::getBudget($questions, new SystemOneConfiguration([]));
            $selfHosted = Evaluator::getBudget($questions, new SystemOneConfiguration(['endpoint' => 'http://ollama:11434/v1/systemone', 'context_tokens' => 2048]));

            $this->assertGreaterThan(50000, $typeSafe);
            // The questions alone are about as large as a small model's context, the state gets the minimum
            $this->assertLessThan(5000, $selfHosted);
        }

        public function testContextExceededIsRetriedSmaller(): void
        {
            $sizes = [];
            FederationJevGuard::setClient(new FakeSystemOneClient(function($state, $questions) use (&$sizes)
            {
                $sizes[] = strlen(json_encode($state));
                if(count($sizes) === 1)
                {
                    throw new SystemOneException('max_tokens_exceeded', 400);
                }

                return Answers::classification($questions, 'NORMAL');
            }));

            $evaluation = Evaluator::evaluate(self::build(40), ClassificationQuestions::build('messages', [], '', false));

            $this->assertCount(2, $sizes);
            $this->assertLessThan($sizes[0], $sizes[1]);
            $this->assertSame('fake-jev', $evaluation->getResponse()->getModel());
        }

        public function testOtherFailuresAreNotRetried(): void
        {
            FederationJevGuard::setClient(FakeSystemOneClient::unreachable());

            $this->expectException(SystemOneException::class);
            Evaluator::evaluate(self::build(1), ClassificationQuestions::build('messages', [], '', false));
        }

        public function testRateLimitStartsARatelimit(): void
        {
            $client = new FakeSystemOneClient(fn() => throw new SystemOneException('rate limited', 429, new SystemOneException('retry-after', 30)));
            FederationJevGuard::setClient($client);
            $questions = ClassificationQuestions::build('messages', [], '', false);

            try
            {
                Evaluator::evaluate(self::build(1), $questions);
                $this->fail('The rate limit was not thrown');
            }
            catch(SystemOneException $e)
            {
                $this->assertTrue($e->isRateLimited());
                $this->assertFalse($e->isRepeat());
            }

            $until = Ratelimit::until(Evaluator::getRateLimitKey(Configuration::getSystemOneConfiguration()));
            $this->assertNotNull($until);
            $this->assertLessThanOrEqual(time() + 30, $until);

            // While the ratelimit lasts the endpoint isn't asked again, the failure is a repeat
            try
            {
                Evaluator::evaluate(self::build(1), $questions);
                $this->fail('The rate limit cooldown was not thrown');
            }
            catch(SystemOneException $e)
            {
                $this->assertTrue($e->isRateLimited());
                $this->assertTrue($e->isRepeat());
            }

            $this->assertCount(1, $client->requests);
        }

        public function testUsedUpBudgetIsRemembered(): void
        {
            // The unit tests have no database, the cooldown alone has to answer without reading the use
            PluginConfiguration::apply(['systemone_daily_tokens' => '1000']);
            Ratelimit::start('budget:1000', 60);
            $client = new FakeSystemOneClient(fn($state, $questions) => Answers::classification($questions, 'NORMAL'));
            FederationJevGuard::setClient($client);

            try
            {
                Evaluator::evaluate(self::build(1), ClassificationQuestions::build('messages', [], '', false));
                $this->fail('The used up budget was not thrown');
            }
            catch(BudgetExceededException $e)
            {
                $this->assertTrue($e->isRepeat());
            }

            $this->assertCount(0, $client->requests);
        }
    }
