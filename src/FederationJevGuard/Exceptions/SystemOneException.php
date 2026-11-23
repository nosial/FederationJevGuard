<?php

    namespace FederationJevGuard\Exceptions;

    use Exception;
    use Throwable;

    class SystemOneException extends Exception
    {
        private bool $repeat = false;

        /**
         * SystemOneException constructor.
         *
         * @param string $message The error
         * @param int $status The HTTP status of the response, 0 if none was received
         * @param Throwable|null $previous The previous exception
         */
        public function __construct(string $message, int $status=0, ?Throwable $previous=null)
        {
            parent::__construct($message, $status, $previous);
        }

        /**
         * Returns True if the failure repeats one that was reported already (eg; while a rate limit ratelimit is
         * active), it's only logged for debugging
         *
         * @return bool True if a repeat
         */
        public function isRepeat(): bool
        {
            return $this->repeat;
        }

        /**
         * Marks the failure as a repeat of one that was reported already
         *
         * @param bool $repeat True if a repeat
         * @return SystemOneException The exception
         */
        public function setRepeat(bool $repeat=true): SystemOneException
        {
            $this->repeat = $repeat;
            return $this;
        }

        /**
         * Returns True if the endpoint is rate limited or overloaded (HTTP 429 or 529)
         *
         * @return bool True if rate limited
         */
        public function isRateLimited(): bool
        {
            return $this->getCode() === 429 || $this->getCode() === 529;
        }

        /**
         * Returns the seconds the endpoint asked to wait before trying again (its retry-after header)
         *
         * @return int|null The seconds, null if it didn't say
         */
        public function getRetryAfter(): ?int
        {
            return $this->getPrevious() instanceof SystemOneException && $previous->getMessage() === 'retry-after' && $previous->getCode() > 0 ? $previous->getCode() : null;
        }

        /**
         * Returns the HTTP status of the response
         *
         * @return int The status, 0 if no response was received
         */
        public function getStatus(): int
        {
            return $this->getCode();
        }

        /**
         * Returns True if the endpoint rejected the request for exceeding the model's context, it may succeed with a
         * smaller state
         *
         * @return bool True if the context was exceeded
         */
        public function isContextExceeded(): bool
        {
            return $this->getCode() === 400 && preg_match('/max_tokens|context|too long|exceed/i', $this->getMessage()) === 1;
        }

        /**
         * Returns True if the request may succeed later as it is, eg; the endpoint was unreachable or overloaded
         *
         * @return bool True if transient
         */
        public function isTransient(): bool
        {
            return $this->getCode() === 0 || $this->getCode() === 408 || $this->getCode() === 429 || $this->getCode() >= 500;
        }
    }
