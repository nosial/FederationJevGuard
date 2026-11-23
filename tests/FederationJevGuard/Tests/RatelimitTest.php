<?php

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\Ratelimit;
    use PHPUnit\Framework\TestCase;

    class RatelimitTest extends TestCase
    {
        private const string KEY = 'test:ratelimit';

        protected function setUp(): void
        {
            Ratelimit::clear(self::KEY);
        }

        protected function tearDown(): void
        {
            Ratelimit::clear(self::KEY);
        }

        public function testNoRatelimitByDefault(): void
        {
            $this->assertNull(Ratelimit::until(self::KEY));
        }

        public function testStartedRatelimitIsActive(): void
        {
            $this->assertTrue(Ratelimit::start(self::KEY, 60));

            $until = Ratelimit::until(self::KEY);
            $this->assertNotNull($until);
            $this->assertGreaterThan(time(), $until);
            $this->assertLessThanOrEqual(time() + 60, $until);
        }

        public function testActiveRatelimitIsNotStartedAgain(): void
        {
            $this->assertTrue(Ratelimit::start(self::KEY, 60));
            $this->assertFalse(Ratelimit::start(self::KEY, 60));
        }

        public function testClearedRatelimitEnds(): void
        {
            Ratelimit::start(self::KEY, 60);
            Ratelimit::clear(self::KEY);

            $this->assertNull(Ratelimit::until(self::KEY));
            $this->assertTrue(Ratelimit::start(self::KEY, 60));
        }

        public function testRatelimitExpires(): void
        {
            Ratelimit::start(self::KEY, 1);
            sleep(2);

            $this->assertNull(Ratelimit::until(self::KEY));
        }
    }
