<?php

    namespace FederationJevGuard\Tests\Helpers;

    use Closure;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\Interfaces\QuestionInterface;
    use FederationJevGuard\Interfaces\SystemOneClientInterface;
    use FederationJevGuard\Objects\SystemOne\SystemOneResponse;

    /**
     * A System One client that answers with a callback and records every request, the unit tests never make a request
     */
    class FakeSystemOneClient implements SystemOneClientInterface
    {
        /** @var Closure(string|array, array<string, array>): array */
        private Closure $answer;
        /** @var array<int, array{state: string|array, questions: array, assets: array, timeout: int|null}> */
        public array $requests = [];

        /**
         * @param Closure(string|array, array<string, array>): array $answer Returns the answers of a request (by question
         *                                                            name), or throws a SystemOneException
         */
        public function __construct(Closure $answer)
        {
            $this->answer = $answer;
        }

        public function evaluate(string|array $state, array $questions, array $assets=[], ?int $timeout=null, int $retries=2): SystemOneResponse
        {
            $questions = array_map(fn(QuestionInterface $question) => $question->toArray(), $questions);
            $this->requests[] = ['state' => $state, 'questions' => $questions, 'assets' => $assets, 'timeout' => $timeout];

            $answers = ($this->answer)($state, $questions);
            return new SystemOneResponse(['model' => 'fake-jev', 'answers' => $answers, 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]], 'jev-latest');
        }

        /**
         * A client whose every request fails as if the endpoint were unreachable
         */
        public static function unreachable(): FakeSystemOneClient
        {
            return new FakeSystemOneClient(fn() => throw new SystemOneException('The System One endpoint is unreachable'));
        }
    }
