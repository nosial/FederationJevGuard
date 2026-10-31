<?php

    namespace FederationJevGuard\Classes;

    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EntityRecord;
    use Normalizer;

    class TextSignals
    {
        public const int MAX_MENTIONS = 5;
        /** @var array<string, array> The mentions resolved in this process, states are built several times to fit */
        private static array $mentions = [];
        private const string INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}\x{180E}\x{115F}\x{1160}\x{3164}\x{FFA0}]/u';

        /**
         * Returns the signals of a text, nothing is returned for an ordinary text
         *
         * @param string|null $text The text
         * @param int $length Optional. The longest readable form of the text, in characters
         * @return array The signals, empty if there are none
         */
        public static function analyze(?string $text, int $length=2000): array
        {
            if($text === null || trim($text) === '')
            {
                return [];
            }

            $text = UntrustedContent::sanitizeText($text);
            $signals = [
                'hidden_characters' => self::countHiddenCharacters($text) ?: null,
                'styled_letters' => self::hasStyledLetters($text) ?: null,
                'mixed_alphabets_in_a_word' => self::hasMixedAlphabets($text) ?: null,
                'digits_for_letters' => self::hasDigitsForLetters($text) ?: null,
                'random_suffix' => self::getRandomSuffix($text) !== null ?: null,
                'spaced_out' => self::isSpacedOut($text) ?: null,
            ];

            if($signals['hidden_characters'] !== null || $signals['styled_letters'] !== null || $signals['spaced_out'] !== null)
            {
                $readable = self::readable($text);
                if($readable !== trim($text))
                {
                    $signals['reads_as'] = ContextBuilder::cut($readable, $length);
                }
            }

            return ContextBuilder::compact($signals);
        }

        /**
         * Returns the fingerprint of a text: the same for the variants of one spam message that only differ by
         * invisible characters, styled letters, letter case, whitespace or a random suffix
         *
         * @param string $text The text
         * @return string The SHA-256 of the normalized text
         */
        public static function fingerprint(string $text): string
        {
            $text = self::readable(UntrustedContent::sanitizeText($text));
            $suffix = self::getRandomSuffix($text);
            if($suffix !== null)
            {
                $text = mb_substr($text, 0, -mb_strlen($suffix));
            }

            return hash('sha256', mb_strtolower((string)preg_replace('/\s+/u', ' ', trim($text))));
        }

        /**
         * Returns the entities of the federated database a text names (domains, URLs, email addresses, telephone numbers
         * and IP addresses, read from the text and from what it reads as without its tricks), with what the database
         * knows about them
         *
         * @param string|null $text The text
         * @param string|null $exclude Optional. The UUID of an entity left out, eg; the sender
         * @return array The entities, at most MAX_MENTIONS
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function mentions(?string $text, ?string $exclude=null): array
        {
            if($text === null || trim($text) === '')
            {
                return [];
            }

            $key = hash('sha256', $text . "\0" . $exclude);
            if(isset(self::$mentions[$key]))
            {
                return self::$mentions[$key];
            }

            $text = UntrustedContent::sanitizeText($text);
            $readable = self::readable($text);
            $entities = EntitiesManager::resolveNamedEntities($readable !== $text ? $text . "\n" . $readable : $text);
            unset($entities[$exclude ?? '']);

            $mentions = [];
            foreach(array_slice($entities, 0, self::MAX_MENTIONS) as $entity)
            {
                $mentions[] = self::mention($entity);
            }

            return self::$mentions[$key] = $mentions;
        }

        /**
         * Forgets the mentions resolved in this process, eg; after a review
         *
         * @return void
         */
        public static function clearCache(): void
        {
            self::$mentions = [];
        }

        /**
         * Describes an entity a text names
         *
         * @param EntityRecord $entity The entity
         * @return array The description
         * @throws DatabaseOperationException If there is an error with the database
         */
        private static function mention(EntityRecord $entity): array
        {
            $blacklists = BlacklistManager::getEntriesByEntity($entity->getUuid(), ContextBuilder::MAX_BLACKLISTS, 1, true);
            $active = array_values(array_filter($blacklists, fn($record) => ContextBuilder::isActive($record)));

            return ContextBuilder::compact([
                'entity' => ContextBuilder::address($entity),
                'reputation' => $entity->getReputation(),
                'whitelisted' => $entity->isWhitelisted() ?: null,
                'blacklists' => count($blacklists) ?: null,
                'active_blacklists' => count($active) ?: null,
                'active_incident_types' => array_values(array_unique(array_map(fn($record) => $record->getType()->value, $active))),
            ]);
        }

        /**
         * Returns the text without invisible characters, with styled and full-width letters as plain letters
         * (Unicode NFKC) and spaced-out words joined, eg; "t . m e" as "t.me"
         *
         * @param string $text The text
         * @return string The readable text
         */
        public static function readable(string $text): string
        {
            $text = self::plainLetters((string)preg_replace(self::INVISIBLE, '', $text));
            if(class_exists(Normalizer::class))
            {
                $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
            }

            $text = (string)preg_replace('/\s*[\(\[\{]\s*(?:dot|точка)\s*[\)\]\}]\s*/iu', '.', $text);
            $text = (string)preg_replace('/(?<=\w)\s+\.\s+(?=(?:com|net|org|me|io|ru|cn|co|gg|ly|xyz|top|app|link|info|biz|vip|cc|tk|site|online|shop|club)\b)/u', '.', $text);
            return trim((string)preg_replace_callback('/(?<!\S)(?:\p{L}[ \x{3000}._\-]){3,}\p{L}(?!\S)/u', fn(array $match) => (string)preg_replace('/[ \x{3000}._\-]/u', '', $match[0]), $text));
        }

        /**
         * Writes styled letters and digits as plain ones: mathematical (eg; "𝐅", "𝓕", "𝔽"), full-width, circled and
         * squared forms. NFKC does the same and more, but needs PHP's intl extension, which not every server has
         *
         * @param string $text The text
         * @return string The text with plain letters
         */
        private static function plainLetters(string $text): string
        {
            return (string)preg_replace_callback('/[\x{1D400}-\x{1D6A3}\x{1D7CE}-\x{1D7FF}\x{FF01}-\x{FF5E}\x{24B6}-\x{24E9}\x{1F130}-\x{1F189}]/u', function(array $match): string
            {
                $code = mb_ord($match[0], 'UTF-8');
                return match(true)
                {
                    // 13 alphabets of 52 letters, A to Z then a to z
                    $code >= 0x1D400 && $code <= 0x1D6A3 => self::letter(($code - 0x1D400) % 52),
                    // 5 sets of the digits 0 to 9
                    $code >= 0x1D7CE && $code <= 0x1D7FF => (string)(($code - 0x1D7CE) % 10),
                    $code >= 0xFF01 && $code <= 0xFF5E => chr($code - 0xFEE0),
                    $code >= 0x24B6 && $code <= 0x24E9 => self::letter($code - 0x24B6),
                    // Squared, circled negative and squared negative capitals
                    default => self::letter(($code - 0x1F130) % 26),
                };
            }, $text);
        }

        /**
         * Returns a letter by its place in the alphabet, capitals first
         *
         * @param int $index 0 to 25 for A to Z, 26 to 51 for a to z
         * @return string The letter
         */
        private static function letter(int $index): string
        {
            return $index < 26 ? chr(0x41 + $index) : chr(0x61 + $index - 26);
        }

        /**
         * Counts the runs of invisible characters within words, those around whitespace, punctuation and at the ends are
         * left out
         *
         * @param string $text The text
         * @return int The number of characters
         */
        private static function countHiddenCharacters(string $text): int
        {
            // Only between letters or digits, eg; "百\u{200D}万", not the padding around punctuation some bots add
            return (int)preg_match_all('/(?<=[\p{L}\p{N}])(?:[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}\x{180E}])+(?=[\p{L}\p{N}])/u', $text);
        }

        /**
         * Checks for letters written with mathematical, full-width, enclosed or other styled forms, eg; "𝐒𝐄𝐗" or "ＳＡＬＥ"
         *
         * @param string $text The text
         * @return bool True if any letter is styled
         */
        private static function hasStyledLetters(string $text): bool
        {
            // Flags are written with regional indicator letters, they are left out
            return preg_match('/[\x{1D400}-\x{1D7FF}\x{FF21}-\x{FF3A}\x{FF41}-\x{FF5A}\x{24B6}-\x{24E9}\x{1F130}-\x{1F189}]{2,}/u', $text) === 1;
        }

        /**
         * Checks for a word that mixes Latin, Cyrillic or Greek letters, eg; "раypal" with a Cyrillic "р" and "а"
         *
         * @param string $text The text
         * @return bool True if a word mixes alphabets
         */
        private static function hasMixedAlphabets(string $text): bool
        {
            preg_match_all('/\p{L}{3,}/u', $text, $words);
            foreach($words[0] as $word)
            {
                $scripts = (preg_match('/\p{Latin}/u', $word) === 1 ? 1 : 0) + (preg_match('/\p{Cyrillic}/u', $word) === 1 ? 1 : 0)
                    + (preg_match('/\p{Greek}/u', $word) === 1 ? 1 : 0);
                if($scripts > 1)
                {
                    return true;
                }
            }

            return false;
        }

        /**
         * Checks for digits or symbols standing in for letters within a word, eg; "SH0WCUYUM" or "fr33 m0ney"
         *
         * @param string $text The text
         * @return bool True if a word has them
         */
        private static function hasDigitsForLetters(string $text): bool
        {
            // Links and addresses are left out, their paths and IDs mix letters and digits
            $text = (string)preg_replace('/\S*(?:https?:\/\/|www\.|\.[a-z]{2,6}\/|@)\S*/iu', ' ', $text);
            return preg_match('/(?<![\p{L}\p{N}])\p{L}{2,}[013457@$]+\p{L}{2,}(?![\p{L}\p{N}])/u', $text) === 1;
        }

        /**
         * Returns the short random token some spam ends with to defeat duplicate detection, eg; "uhzS" after a Chinese
         * text: 1 to 5 Latin letters or digits after a text written in another script
         *
         * @param string $text The text
         * @return string|null The token, null if there is none
         */
        public static function getRandomSuffix(string $text): ?string
        {
            // Styled letters count as the Latin letters they stand for
            $text = rtrim(self::readable($text));
            if(preg_match('/\s([A-Za-z0-9]{1,5})$/u', $text, $match) !== 1)
            {
                return null;
            }

            $body = mb_substr($text, 0, -mb_strlen($match[1]));
            $other = preg_match_all('/[^\p{Latin}\p{N}\p{P}\p{S}\s\p{M}]/u', $body);
            $latin = preg_match_all('/\p{Latin}/u', $body);
            return $other >= 4 && $other > $latin * 3 ? $match[1] : null;
        }

        /**
         * Checks for a word or address spelled out with spaces or a written-out dot, eg; "t . me", "w h a t s a p p" or
         * "site (dot) com"
         *
         * @param string $text The text
         * @return bool True if spaced out
         */
        private static function isSpacedOut(string $text): bool
        {
            return preg_match('/(?<!\S)(?:\p{Latin}[ ._\-]){4,}\p{Latin}(?!\S)|(?i:[\(\[\{]\s*(?:dot|точка)\s*[\)\]\}])|(?<=\w)\s+\.\s+(?=(?:com|net|org|me|io|ru|cn|co|gg|ly|xyz|top|app|link|info|biz|vip|cc|tk|site|online|shop|club)\b)/u', $text) === 1;
        }
    }
