<?php

    namespace FederationJevGuard\Classes;

    use FederationLib\Classes\RedisConnection;
    use Redis;
    use Throwable;

    final class Ratelimit
    {
        private const string PREFIX = 'federation_jevguard:ratelimit:';
        private const int REDIS_RETRY = 60;

        /** @var array<string, int> The end of the cooldowns kept in the memory of the process */
        private static array $local = [];
        private static int $redisFailedAt = 0;

        /**
         * Returns the end of a cooldown
         *
         * @param string $key The cooldown
         * @return int|null The Unix time the cooldown ends, null if none is active
         */
        public static function until(string $key): ?int
        {
            $name = self::PREFIX . $key;
            $until = self::$local[$name] ?? null;
            $redis = self::getRedis();

            if($redis !== null)
            {
                try
                {
                    $value = $redis->get($name);
                    $until = is_numeric($value) ? (int)$value : null;
                }
                catch(Throwable $e)
                {
                    self::redisFailed($e);
                }
            }
            elseif(self::isApcuAvailable())
            {
                $value = apcu_fetch($name, $success);
                $until = $success && is_numeric($value) ? (int)$value : null;
            }

            if($until === null || $until <= time())
            {
                unset(self::$local[$name]);
                return null;
            }

            return $until;
        }

        /**
         * Starts a cooldown unless one is active
         *
         * @param string $key The cooldown
         * @param int $seconds The seconds the cooldown lasts, at least one
         * @return bool True if this call started the cooldown, False if one was active already
         */
        public static function start(string $key, int $seconds): bool
        {
            $name = self::PREFIX . $key;
            $seconds = max(1, $seconds);
            $until = time() + $seconds;

            if(self::until($key) !== null)
            {
                return false;
            }

            // The cooldown is kept in the process as well, a cache that fails later doesn't end it early
            self::$local[$name] = $until;

            $redis = self::getRedis();
            if($redis !== null)
            {
                try
                {
                    return $redis->set($name, (string)$until, ['nx', 'ex' => $seconds]) === true;
                }
                catch(Throwable $e)
                {
                    self::redisFailed($e);
                }
            }
            elseif(self::isApcuAvailable())
            {
                return apcu_add($name, $until, $seconds);
            }

            return true;
        }

        /**
         * Ends a cooldown
         *
         * @param string $key The cooldown
         * @return void
         */
        public static function clear(string $key): void
        {
            $name = self::PREFIX . $key;
            unset(self::$local[$name]);

            $redis = self::getRedis();
            if($redis !== null)
            {
                try
                {
                    $redis->del($name);
                }
                catch(Throwable $e)
                {
                    self::redisFailed($e);
                }
            }
            elseif(self::isApcuAvailable())
            {
                apcu_delete($name);
            }
        }

        /**
         * Returns FederationLib's Redis connection, null if it's disabled or failed recently
         *
         * @return Redis|null The connection
         */
        private static function getRedis(): ?Redis
        {
            if(self::$redisFailedAt > 0 && time() - self::$redisFailedAt < self::REDIS_RETRY)
            {
                return null;
            }

            try
            {
                return RedisConnection::getConnection();
            }
            catch(Throwable $e)
            {
                self::redisFailed($e);
                return null;
            }
        }

        /**
         * Stops using Redis for a while after it failed, the cooldowns fall back to APCu or the process
         *
         * @param Throwable $e The failure
         * @return void
         */
        private static function redisFailed(Throwable $e): void
        {
            if(self::$redisFailedAt === 0)
            {
                Logger::log()->debug('Ratelimits are not shared through Redis: ' . $e->getMessage());
            }

            self::$redisFailedAt = time();
        }

        /**
         * Returns True if APCu is available, it's only shared between processes outside the CLI
         *
         * @return bool True if available
         */
        private static function isApcuAvailable(): bool
        {
            return PHP_SAPI !== 'cli' && function_exists('apcu_add') && apcu_enabled();
        }
    }
