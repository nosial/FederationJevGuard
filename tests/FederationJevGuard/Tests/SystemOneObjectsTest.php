<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\Objects\SystemOne\ChoiceQuestion;
    use FederationJevGuard\Objects\SystemOne\NoulQuestion;
    use FederationJevGuard\Objects\SystemOne\ScoreQuestion;
    use FederationJevGuard\Objects\SystemOne\SystemOneResponse;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;

    class SystemOneObjectsTest extends TestCase
    {
        public function testQuestionsAreSentInTheApiShape(): void
        {
            $this->assertSame(['type' => 'noul', 'instructions' => 'Is it spam?'], new NoulQuestion('Is it spam?')->toArray());
            $this->assertSame(['type' => 'noul', 'instructions' => 'Is it spam?', 'criteria' => ['true' => 'Spam']], new NoulQuestion('Is it spam?', 'Spam')->toArray());
            $this->assertSame(['type' => 'choice', 'instructions' => 'Tone?', 'criteria' => ['calm' => null, 'angry' => 'Upset']], new ChoiceQuestion('Tone?', ['calm' => null, 'angry' => 'Upset'])->toArray());
            $this->assertSame(['type' => 'score', 'instructions' => 'How bad?', 'criteria' => ['Mild', 'Severe']], new ScoreQuestion('How bad?', ['Mild', 'Severe'])->toArray());
        }

        public function testReversedChoiceKeepsItsOptions(): void
        {
            $question = new ChoiceQuestion('Which?', ['a' => 'A', 'b' => 'B', 'c' => 'C']);

            $this->assertSame(['c', 'b', 'a'], $question->reversed()->getOptions());
            $this->assertSame(['c' => 'C', 'b' => 'B', 'a' => 'A'], $question->reversed()->toArray()['criteria']);
        }

        public function testInvalidQuestionsAreRejected(): void
        {
            $this->expectException(InvalidArgumentException::class);
            new ScoreQuestion('How bad?', ['Only one level']);
        }

        public function testResponseIsParsed(): void
        {
            $response = new SystemOneResponse([
                'model' => 'jev-1.14.0',
                'answers' => [
                    'spam' => ['type' => 'noul', 'noul' => 0.98],
                    'tone' => ['type' => 'choice', 'choice' => 'calm', 'confidence' => 0.7, 'probabilities' => ['angry' => 0.15, 'calm' => 0.8, 'excited' => 0.05]],
                    'urgency' => ['type' => 'score', 'score' => 1.7, 'confidence' => 0.55, 'legend' => ['0' => 'a', '1' => 'b', '2' => 'c'], 'probabilities' => ['0' => 0.1, '1' => 0.1, '2' => 0.8]],
                ],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 12],
            ], 'jev-latest');

            $this->assertSame('jev-1.14.0', $response->getModel());
            $this->assertSame(0.98, $response->noul('spam')->getNoul());
            $this->assertSame('calm', $response->choice('tone')->getChoice());
            $this->assertSame(0.8, $response->choice('tone')->getProbability('calm'));
            $this->assertSame(0.0, $response->choice('tone')->getProbability('missing'));
            $this->assertSame(1.7, $response->score('urgency')->getScore());
            $this->assertSame(120, $response->getInputTokens());
            $this->assertTrue($response->has('spam'));
            $this->assertFalse($response->has('missing'));
            // A missing noul is unsure rather than a no
            $this->assertSame(0.5, $response->noul('missing')->getNoul());
        }

        public function testChoiceWithoutProbabilitiesIsCertainOfItsChoice(): void
        {
            // eg; a self-hosted endpoint that only returns the choice
            $response = new SystemOneResponse(['answers' => ['tone' => ['type' => 'choice', 'choice' => 'calm']]], 'tev1:4b');

            $this->assertSame('tev1:4b', $response->getModel());
            $this->assertSame(1.0, $response->choice('tone')->getProbability('calm'));
            $this->assertSame(0.0, $response->choice('tone')->getProbability('angry'));
        }

        public function testResponseWithoutAnswersIsRejected(): void
        {
            $this->expectException(InvalidArgumentException::class);
            new SystemOneResponse(['model' => 'jev-1.14.0', 'answers' => []], 'jev-latest');
        }

        public function testFailuresAreClassified(): void
        {
            $this->assertTrue(new SystemOneException('max_tokens_exceeded: too long', 400)->isContextExceeded());
            $this->assertFalse(new SystemOneException('invalid UTF-8', 400)->isContextExceeded());
            $this->assertTrue(new SystemOneException('unreachable')->isTransient());
            $this->assertTrue(new SystemOneException('rate limited', 429)->isTransient());
            $this->assertTrue(new SystemOneException('overloaded', 529)->isTransient());
            $this->assertFalse(new SystemOneException('invalid', 422)->isTransient());
            $this->assertFalse(new SystemOneException('unauthorized', 401)->isTransient());
        }
    }
