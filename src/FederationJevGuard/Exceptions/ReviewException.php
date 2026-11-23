<?php

    namespace FederationJevGuard\Exceptions;

    use Exception;
    use Throwable;

    class ReviewException extends Exception
    {
        private bool $repeat;

        /**
         * ReviewException constructor.
         *
         * @param string $message The error
         * @param int $code The code
         * @param Throwable|null $previous The previous exception
         * @param bool $repeat True if the failure repeats one that was reported already (eg; while a ratelimit is active)
         */
        public function __construct(string $message='', int $code=0, ?Throwable $previous=null, bool $repeat=false)
        {
            parent::__construct($message, $code, $previous);
            $this->repeat = $repeat;
        }

        /**
         * Returns True if the failure repeats one that was reported already, it's only logged for debugging
         *
         * @return bool True if a repeat
         */
        public function isRepeat(): bool
        {
            return $this->repeat;
        }
    }
