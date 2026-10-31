<?php

    namespace FederationJevGuard\Classes;

    use FederationJevGuard\Objects\SystemOne\ChoiceQuestion;
    use FederationJevGuard\Objects\SystemOne\NoulQuestion;
    use FederationJevGuard\Objects\SystemOne\ScoreQuestion;
    use FederationLib\Enums\IncidentType;
    use RuntimeException;

    class QuestionSet
    {
        public const string CLASSIFICATION = 'classification';
        public const string BLACKLIST = 'blacklist';
        public const string IMAGE = 'image';
        public const string CONCERNS = 'concerns';
        public const array SECTIONS = [self::CLASSIFICATION, self::BLACKLIST, self::IMAGE];
        public const array TYPES = ['noul', 'choice', 'score'];

        private static ?QuestionSet $instance = null;
        private static ?string $instancePath = null;
        private static ?array $bundled = null;

        private array $data;
        private string $version;
        private bool $isBundled;

        /**
         * QuestionSet constructor.
         *
         * @param array $data The decoded question file, valid according to validate()
         * @param string $hash The SHA-256 of the file's contents
         * @param bool $isBundled True for the file bundled with the plugin
         */
        private function __construct(array $data, string $hash, bool $isBundled)
        {
            $this->data = $data;
            $this->version = mb_substr(sprintf('%s+%s%s', $data['version'], $isBundled ? '' : 'custom.', substr($hash, 0, 12)), 0, 64);
            $this->isBundled = $isBundled;
        }

        /**
         * Returns the questions in use: the configured file's, or the bundled ones when none is configured or the file
         * is refused
         *
         * @return QuestionSet The questions
         */
        public static function get(): QuestionSet
        {
            $path = Configuration::getQuestionsPath();
            if(self::$instance !== null && self::$instancePath === $path)
            {
                return self::$instance;
            }

            self::$instancePath = $path;
            self::$instance = null;
            if($path !== null)
            {
                try
                {
                    self::$instance = self::load($path, false);
                    Logger::log()->info(sprintf('FederationJevGuard asks the questions of %s (%s)', $path, self::$instance->getVersion()));
                }
                catch(RuntimeException $e)
                {
                    Logger::log()->error(sprintf('The questions of %s are refused, the bundled questions are asked instead: %s', $path, $e->getMessage()));
                }
            }

            return self::$instance ??= self::load(self::getBundledPath(), true);
        }

        /**
         * Returns the path of the question file bundled with the plugin
         *
         * @return string The path
         */
        public static function getBundledPath(): string
        {
            return __DIR__ . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'questions.json';
        }

        /**
         * Loads and validates a question file
         *
         * @param string $path The path of the file
         * @param bool $isBundled True for the bundled file
         * @return QuestionSet The questions
         * @throws RuntimeException If the file can't be read or isn't valid
         */
        public static function load(string $path, bool $isBundled=false): QuestionSet
        {
            $contents = is_file($path) ? @file_get_contents($path) : false;
            if($contents === false)
            {
                throw new RuntimeException("The question file $path can't be read");
            }

            $data = json_decode($contents, true);
            if(!is_array($data))
            {
                throw new RuntimeException("The question file $path is not valid JSON: " . json_last_error_msg());
            }

            $errors = self::validate($data, $isBundled ? null : self::getBundled());
            if(count($errors) > 0)
            {
                throw new RuntimeException(implode('; ', array_slice($errors, 0, 10)));
            }

            return new QuestionSet($data, hash('sha256', $contents), $isBundled);
        }

        /**
         * Validates the decoded contents of a question file
         *
         * @param array $data The decoded file
         * @param array|null $reference The questions a file needs, the bundled file's; null to only check the structure
         * @return string[] What is wrong with the file, empty if it's valid
         */
        public static function validate(array $data, ?array $reference=null): array
        {
            $errors = [];
            if(!is_string($data['version'] ?? null) || trim($data['version']) === '')
            {
                $errors[] = '`version` must be a non-empty string';
            }

            foreach(self::SECTIONS as $section)
            {
                if(!is_array($data[$section] ?? null) || count($data[$section]) === 0)
                {
                    $errors[] = "`$section` must be an object of questions";
                    continue;
                }

                foreach($data[$section] as $name => $question)
                {
                    foreach(self::validateQuestion("$section.$name", $question) as $error)
                    {
                        $errors[] = $error;
                    }
                }

                if($reference === null)
                {
                    continue;
                }

                // A file only changes the wording: the code asks and judges the same questions whatever the file says
                foreach(array_diff(array_keys($reference[$section]), array_keys($data[$section])) as $missing)
                {
                    $errors[] = "`$section.$missing` is missing";
                }

                foreach(array_diff(array_keys($data[$section]), array_keys($reference[$section])) as $unknown)
                {
                    $errors[] = "`$section.$unknown` is not a question the plugin asks";
                }

                foreach($reference[$section] as $name => $expected)
                {
                    $question = $data[$section][$name] ?? null;
                    if(!is_array($question))
                    {
                        continue;
                    }

                    if(($question['type'] ?? null) !== $expected['type'])
                    {
                        $errors[] = "`$section.$name` must be a {$expected['type']} question";
                        continue;
                    }

                    if(isset($expected['options']) && !is_array($question['options'] ?? null))
                    {
                        $errors[] = "`$section.$name` must have options";
                    }
                    elseif(isset($expected['options']))
                    {
                        $options = array_map('strval', array_keys($question['options']));
                        $expectedOptions = array_map('strval', array_keys($expected['options']));
                        sort($options);
                        sort($expectedOptions);
                        if($options !== $expectedOptions)
                        {
                            $errors[] = sprintf('`%s.%s` must have the options %s', $section, $name, implode(', ', $expectedOptions));
                        }
                    }

                    // The judges read the score as a level of the bundled scale
                    if(isset($expected['levels']) && is_array($question['levels'] ?? null) && count($question['levels']) !== count($expected['levels']))
                    {
                        $errors[] = sprintf('`%s.%s` must have %d levels', $section, $name, count($expected['levels']));
                    }
                }
            }

            if(!is_array($data[self::CONCERNS] ?? null))
            {
                $errors[] = '`concerns` must be an object of descriptions';
            }
            else
            {
                foreach(array_merge(array_map(fn(IncidentType $type) => $type->value, IncidentType::cases()), ['default']) as $concern)
                {
                    if(isset($data[self::CONCERNS][$concern]) && !self::isText($data[self::CONCERNS][$concern]))
                    {
                        $errors[] = "`concerns.$concern` must be a non-empty string";
                    }
                }

                if(!self::isText($data[self::CONCERNS]['default'] ?? null))
                {
                    $errors[] = '`concerns.default` must be a non-empty string';
                }
            }

            return $errors;
        }

        /**
         * Validates the structure of one question
         *
         * @param string $name The name of the question, for the errors
         * @param mixed $question The question
         * @return string[] What is wrong with the question
         */
        private static function validateQuestion(string $name, mixed $question): array
        {
            if(!is_array($question))
            {
                return ["`$name` must be an object"];
            }

            $errors = [];
            $type = $question['type'] ?? null;
            if(!in_array($type, self::TYPES, true))
            {
                $errors[] = sprintf('`%s.type` must be one of %s', $name, implode(', ', self::TYPES));
            }

            if(!self::isCriterion($question['instructions'] ?? null))
            {
                $errors[] = "`$name.instructions` must be a non-empty string or an object of strings";
            }

            if($type === 'noul')
            {
                foreach(['true', 'false'] as $key)
                {
                    if(array_key_exists($key, $question) && !self::isCriterion($question[$key]))
                    {
                        $errors[] = "`$name.$key` must be a string or an object";
                    }
                }
            }

            if($type === 'choice' && array_key_exists('options', $question))
            {
                if(!is_array($question['options']) || count($question['options']) === 0 || array_is_list($question['options']))
                {
                    $errors[] = "`$name.options` must be an object of options";
                }
                else
                {
                    foreach($question['options'] as $option => $criterion)
                    {
                        if(!self::isCriterion($criterion))
                        {
                            $errors[] = "`$name.options.$option` must be a string or an object";
                        }
                    }
                }
            }

            if($type === 'score')
            {
                $levels = $question['levels'] ?? null;
                if(!is_array($levels) || !array_is_list($levels) || count($levels) < 2 || count($levels) > 10)
                {
                    $errors[] = "`$name.levels` must be a list of 2 to 10 levels";
                }
                else
                {
                    foreach($levels as $index => $level)
                    {
                        if(!self::isCriterion($level))
                        {
                            $errors[] = "`$name.levels[$index]` must be a string or an object";
                        }
                    }
                }
            }

            return $errors;
        }

        /**
         * Returns True if a value is a non-empty string
         *
         * @param mixed $value The value
         * @return bool True if it's text
         */
        private static function isText(mixed $value): bool
        {
            return is_string($value) && trim($value) !== '';
        }

        /**
         * Returns True if a value can be sent as instructions or criteria: a non-empty string, or a non-empty object or
         * list holding only strings, lists and objects of strings
         *
         * @param mixed $value The value
         * @return bool True if valid
         */
        private static function isCriterion(mixed $value): bool
        {
            if(is_string($value))
            {
                return trim($value) !== '';
            }

            if(!is_array($value) || count($value) === 0)
            {
                return false;
            }

            foreach($value as $item)
            {
                if(!is_string($item) && !self::isCriterion($item))
                {
                    return false;
                }
            }

            return true;
        }

        /**
         * Returns the decoded bundled file, the reference a configured file is validated against
         *
         * @return array The bundled questions
         * @throws RuntimeException If the bundled file can't be read
         */
        private static function getBundled(): array
        {
            if(self::$bundled === null)
            {
                $data = json_decode((string)@file_get_contents(self::getBundledPath()), true);
                if(!is_array($data))
                {
                    throw new RuntimeException('The bundled question file can\'t be read');
                }

                self::$bundled = $data;
            }

            return self::$bundled;
        }

        /**
         * Returns the version of the questions, recorded with every decision: the file's `version` and the start of
         * the SHA-256 of its contents, with `custom.` before the hash when it's not the bundled file, eg;
         * `1.0.0+3f2a9c41d0be` or `1.0.0+custom.77e01ab5c2f9`
         *
         * @return string The version
         */
        public function getVersion(): string
        {
            return $this->version;
        }

        /**
         * Returns True if these are the questions bundled with the plugin
         *
         * @return bool True if bundled
         */
        public function isBundled(): bool
        {
            return $this->isBundled;
        }

        /**
         * Returns a Noul question
         *
         * @param string $section The section of the question, eg; classification
         * @param string $name The name of the question
         * @param array<string, string|int> $placeholders The values of the placeholders, eg; ['path' => 'messages']
         * @return NoulQuestion The question
         */
        public function noul(string $section, string $name, array $placeholders=[]): NoulQuestion
        {
            $question = $this->question($section, $name, 'noul', $placeholders);
            return new NoulQuestion($question['instructions'], $question['true'] ?? null, $question['false'] ?? null);
        }

        /**
         * Returns a Choice question
         *
         * @param string $section The section of the question
         * @param string $name The name of the question
         * @param array<string, string|int> $placeholders The values of the placeholders
         * @param array<string, string|array>|null $options Optional. The options, when the code chooses them (eg; the
         *                                                   incident types); the file's options otherwise
         * @return ChoiceQuestion The question
         */
        public function choice(string $section, string $name, array $placeholders=[], ?array $options=null): ChoiceQuestion
        {
            $question = $this->question($section, $name, 'choice', $placeholders);
            return new ChoiceQuestion($question['instructions'], $options ?? $question['options'] ?? []);
        }

        /**
         * Returns a Score question
         *
         * @param string $section The section of the question
         * @param string $name The name of the question
         * @param array<string, string|int> $placeholders The values of the placeholders
         * @return ScoreQuestion The question
         */
        public function score(string $section, string $name, array $placeholders=[]): ScoreQuestion
        {
            $question = $this->question($section, $name, 'score', $placeholders);
            return new ScoreQuestion($question['instructions'], $question['levels']);
        }

        /**
         * Returns the names of the questions of a section, in the file's order
         *
         * @param string $section The section
         * @return string[] The names
         */
        public function names(string $section): array
        {
            return array_map('strval', array_keys($this->data[$section] ?? []));
        }

        /**
         * Describes a concern as an option of the incident type
         *
         * @param IncidentType $concern The concern
         * @return string The description
         */
        public function describeConcern(IncidentType $concern): string
        {
            return $this->data[self::CONCERNS][$concern->value] ?? $this->data[self::CONCERNS]['default'];
        }

        /**
         * Returns a question with its placeholders filled in
         *
         * @param string $section The section of the question
         * @param string $name The name of the question
         * @param string $type The type the code expects
         * @param array<string, string|int> $placeholders The values of the placeholders
         * @return array The question
         * @throws RuntimeException If the question doesn't exist or has another type, only possible when the code and
         *                          the bundled file disagree
         */
        private function question(string $section, string $name, string $type, array $placeholders): array
        {
            $question = $this->data[$section][$name] ?? null;
            if(!is_array($question) || ($question['type'] ?? null) !== $type)
            {
                throw new RuntimeException("The question $section.$name is not a $type question");
            }

            $search = array_map(fn(string $key) => '{' . $key . '}', array_keys($placeholders));
            $replace = array_map('strval', array_values($placeholders));
            array_walk_recursive($question, function(&$value) use ($search, $replace)
            {
                if(is_string($value))
                {
                    $value = str_replace($search, $replace, $value);
                }
            });

            return $question;
        }
    }
