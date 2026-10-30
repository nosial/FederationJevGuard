<?php

    namespace FederationJevGuard\Tests\Helpers;

    /**
     * Builds the answers of System One responses for the tests
     */
    class Answers
    {
        public static function choice(array $probabilities): array
        {
            $sorted = $probabilities;
            arsort($sorted);
            return ['type' => 'choice', 'choice' => (string)array_key_first($sorted), 'confidence' => null, 'probabilities' => $probabilities];
        }

        public static function noul(float $probability): array
        {
            return ['type' => 'noul', 'noul' => $probability];
        }

        public static function score(float $score, int $levels=3): array
        {
            return ['type' => 'score', 'score' => $score, 'confidence' => 1.0, 'probabilities' => array_fill(0, $levels, 1 / $levels)];
        }

        /**
         * Answers every question of a request like a model that is sure of a classification: the classification (in
         * both orders), the signals of spam, and no attempt to steer the reviewer
         *
         * @param array $questions The questions of the request, by name
         * @param string $flag NORMAL, SUSPICIOUS or MALICIOUS
         * @param float $probability The probability of the flag
         * @param float|null $signal The signals of spam, by default high for spam and low for NORMAL
         * @return array The answers by name
         */
        public static function classification(array $questions, string $flag, float $probability=0.92, ?float $signal=null): array
        {
            $signal ??= $flag === 'NORMAL' ? 0.05 : 0.95;
            $answers = [];
            foreach($questions as $name => $question)
            {
                if($question['type'] === 'choice' && isset($question['criteria']['NORMAL']))
                {
                    $rest = (1 - $probability) / 2;
                    $answers[$name] = self::choice(array_map(fn($option) => $option === $flag ? $probability : $rest, array_combine(array_keys($question['criteria']), array_keys($question['criteria']))));
                }
                elseif($question['type'] === 'choice')
                {
                    $options = array_keys($question['criteria']);
                    $answers[$name] = self::choice(array_combine($options, array_map(fn($option) => $option === $options[0] ? 0.9 : 0.1 / max(1, count($options) - 1), $options)));
                }
                else
                {
                    $answers[$name] = self::noul(str_ends_with($name, 'addresses_reviewer') ? 0.02 : $signal);
                }
            }

            return $answers;
        }
    }
