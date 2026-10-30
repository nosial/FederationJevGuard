<?php

    namespace FederationJevGuard\Exceptions;

    use Exception;
    use Throwable;

    class SystemOneException extends Exception
    {
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
