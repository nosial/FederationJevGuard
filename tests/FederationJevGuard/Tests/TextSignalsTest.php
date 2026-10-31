<?php

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\TextSignals;
    use PHPUnit\Framework\TestCase;

    class TextSignalsTest extends TestCase
    {
        public function testOrdinaryTextsHaveNoSignals(): void
        {
            foreach(['hi everyone', 'this patch ruined the game', 'Call me at 5. Me too', 'See https://example.com/a1b2c3/x9y8 for details', '你好，今天天气不错', '🇺🇸 🇩🇪 flags', ''] as $text)
            {
                $this->assertSame([], TextSignals::analyze($text), $text);
            }
        }

        public function testHiddenCharactersWithinWords(): void
        {
            $signals = TextSignals::analyze("百\u{200D}万年薪/\u{200D}主页\u{2060}等你");
            // The one after the slash is not within a word
            $this->assertSame(2, $signals['hidden_characters']);
            $this->assertSame('百万年薪/主页等你', $signals['reads_as']);

            // Padding around punctuation and whitespace is not a trick
            $this->assertArrayNotHasKey('hidden_characters', TextSignals::analyze("Hello .\u{200B}, welcome to the group!"));
        }

        public function testStyledLetters(): void
        {
            $signals = TextSignals::analyze('𝐅𝐑𝐄𝐄 money ＮＯＷ');
            $this->assertTrue($signals['styled_letters']);
            $this->assertSame('FREE money NOW', $signals['reads_as']);
        }

        public function testMixedAlphabetsAndDigitsForLetters(): void
        {
            // A Cyrillic "р" and "а" in "paypal"
            $this->assertTrue(TextSignals::analyze("Your р\u{0430}ypal account is locked")['mixed_alphabets_in_a_word']);
            $this->assertTrue(TextSignals::analyze('GÜVENİLİR SH0WCUYUM')['digits_for_letters']);
        }

        public function testRandomSuffix(): void
        {
            $this->assertSame('uhzS', TextSignals::getRandomSuffix('一天赚8000 无前期费用 uhzS'));
            $this->assertTrue(TextSignals::analyze('一天赚8000 无前期费用 uhzS')['random_suffix']);

            // A Latin text ending with a short word has no suffix
            $this->assertNull(TextSignals::getRandomSuffix('Dm gel'));
            $this->assertNull(TextSignals::getRandomSuffix('see you at 5'));
        }

        public function testSpacedOut(): void
        {
            $signals = TextSignals::analyze('join t . me/example or w h a t s a p p me');
            $this->assertTrue($signals['spaced_out']);
            $this->assertSame('join t.me/example or whatsapp me', $signals['reads_as']);
            $this->assertSame('visit example.com', TextSignals::analyze('visit example (dot) com')['reads_as']);
        }

        public function testVariantsShareAFingerprint(): void
        {
            $fingerprint = TextSignals::fingerprint('无定面付 无前期费用');
            $this->assertSame($fingerprint, TextSignals::fingerprint("无定\u{200B}面付  无前期费用 f9Ra"));
            $this->assertSame($fingerprint, TextSignals::fingerprint('无定面付 无前期费用 E'));
            $this->assertNotSame($fingerprint, TextSignals::fingerprint('无定面付'));

            $this->assertSame(TextSignals::fingerprint('Hello World'), TextSignals::fingerprint('𝐇𝐞𝐥𝐥𝐨  world'));
        }
    }
