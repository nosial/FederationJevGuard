<?php

    namespace FederationJevGuard\Classes;

    use CURLStringFile;
    use FederationJevGuard\Classes\Configuration\SystemOneConfiguration;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\Interfaces\QuestionInterface;
    use FederationJevGuard\Interfaces\SystemOneClientInterface;
    use FederationJevGuard\Objects\SystemOne\ImageAsset;
    use FederationJevGuard\Objects\SystemOne\SystemOneResponse;
    use InvalidArgumentException;
    use JsonException;

    class SystemOneClient implements SystemOneClientInterface
    {
        private const int MAX_RETRY_DELAY = 10;

        private SystemOneConfiguration $configuration;

        /**
         * SystemOneClient constructor.
         *
         * @param SystemOneConfiguration|null $configuration Optional. The endpoint and model, the plugin's
         *                                                   configuration by default
         */
        public function __construct(?SystemOneConfiguration $configuration=null)
        {
            $this->configuration = $configuration ?? Configuration::getSystemOneConfiguration();
        }

        /**
         * @inheritDoc
         */
        public function evaluate(string|array $state, array $questions, array $assets=[], ?int $timeout=null, int $retries=2): SystemOneResponse
        {
            if(count($questions) === 0)
            {
                throw new SystemOneException('A request needs at least one question', 400);
            }

            $request = [
                'model' => $this->configuration->getModel(),
                'state' => $state,
                'questions' => array_map(fn(QuestionInterface $question) => $question->toArray(), $questions),
            ];

            $attempt = 0;
            while(true)
            {
                try
                {
                    return $this->send($request, $assets, $timeout ?? $this->configuration->getTimeout());
                }
                catch(SystemOneException $e)
                {
                    if(($e->getStatus() !== 429 && $e->getStatus() !== 529) || $attempt >= $retries)
                    {
                        throw $e;
                    }

                    $attempt++;
                    $delay = min(self::MAX_RETRY_DELAY, $e->getPrevious()?->getCode() ?: 2 ** $attempt);
                    Logger::log()->debug(sprintf('System One is rate limited or overloaded (HTTP %d), trying again in %d second(s)', $e->getStatus(), $delay));
                    sleep($delay);
                }
            }
        }

        /**
         * Sends a request, as JSON or, with images, as multipart/form-data with the JSON as its first part
         *
         * @param array $request The request body
         * @param ImageAsset[] $assets The images
         * @param int $timeout The timeout in seconds
         * @return SystemOneResponse The answers
         * @throws SystemOneException If the request failed, the code of a 429/529 previous exception is its retry-after
         */
        private function send(array $request, array $assets, int $timeout): SystemOneResponse
        {
            $headers = ['Accept: application/json', 'User-Agent: FederationJevGuard/1.0.0'];
            if($this->configuration->getApiKey() !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $this->configuration->getApiKey();
            }

            if($this->configuration->isLongContext())
            {
                $headers[] = 'TypeSafe-Danger-Zone-Long-Context: true';
            }

            try
            {
                if(count($assets) === 0)
                {
                    $headers[] = 'Content-Type: application/json';
                    $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                }
                else
                {
                    // Images are sent as they are in their own parts, base64 would make them a third larger
                    $headers[] = 'TypeSafe-Danger-Zone-Images: true';
                    $request['assets'] = [];
                    $parts = [];
                    foreach(array_values($assets) as $index => $asset)
                    {
                        $part = 'image' . $index;
                        $request['assets'][$asset->getReference()] = ['type' => 'image', 'part' => $part];
                        $parts[$part] = new CURLStringFile($asset->getData(), $part . '.' . $asset->getFormat(), $asset->getMimeType());
                    }

                    $body = ['request' => new CURLStringFile(json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 'request.json', 'application/json')] + $parts;
                }
            }
            catch(JsonException $e)
            {
                throw new SystemOneException('The request could not be encoded: ' . $e->getMessage(), 400, $e);
            }

            $retryAfter = 0;
            $curl = curl_init($this->configuration->getEndpoint());
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => function($curl, string $header) use (&$retryAfter): int
                {
                    if(preg_match('/^retry-after:\s*(\d+)/i', $header, $matches) === 1)
                    {
                        $retryAfter = (int)$matches[1];
                    }

                    return strlen($header);
                },
            ]);

            $response = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if($response === false)
            {
                throw new SystemOneException('The System One endpoint is unreachable: ' . $error);
            }

            $data = json_decode((string)$response, true);
            if($status !== 200)
            {
                throw new SystemOneException(sprintf('The System One request failed with HTTP %d: %s', $status, self::describeError($data, (string)$response)),
                    $status, $retryAfter > 0 ? new SystemOneException('retry-after', $retryAfter) : null);
            }

            if(!is_array($data))
            {
                throw new SystemOneException('The System One endpoint returned an invalid response', 502);
            }

            try
            {
                return new SystemOneResponse($data, $this->configuration->getModel());
            }
            catch(InvalidArgumentException $e)
            {
                throw new SystemOneException('The System One endpoint returned an invalid response: ' . $e->getMessage(), 502, $e);
            }
        }

        /**
         * Describes the error of a failed request: TypeSafe responds with {"detail": ...}, self-hosted endpoints such as
         * Ollama with {"error": ...}
         *
         * @param mixed $data The decoded response
         * @param string $response The raw response
         * @return string The error
         */
        private static function describeError(mixed $data, string $response): string
        {
            if(is_array($data))
            {
                $detail = $data['detail'] ?? $data['error'] ?? null;
                if(is_string($detail))
                {
                    return $detail;
                }

                if(is_array($detail))
                {
                    return json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'unknown error';
                }
            }

            $preview = trim(mb_substr(strip_tags($response), 0, 200));
            return $preview !== '' ? $preview : 'no response body';
        }
    }
