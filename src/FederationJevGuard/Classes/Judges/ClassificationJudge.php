<?php

    namespace FederationJevGuard\Classes\Judges;

    use FederationJevGuard\Classes\Questions\ClassificationQuestions;
    use FederationJevGuard\Objects\SystemOne\SystemOneResponse;
    use FederationJevGuard\Objects\Verdict;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;

    class ClassificationJudge
    {
        public const float REPORT_THRESHOLD = 0.75;
        public const float EVIDENCE_THRESHOLD = 0.85;
        public const float SCAN_THRESHOLD = 0.6;
        public const float SIGNAL_YES = 0.7;
        public const float SIGNAL_NO = 0.3;
        public const float INJECTION = 0.7;
        /**
         * The probability of spam needed to call content SUSPICIOUS when the model is unsure which kind of spam it is,
         * whatever the threshold of the action. With three options, nearly any answer that isn't clearly NORMAL adds up
         * to the scan threshold, eg; MALICIOUS=0.4, SUSPICIOUS=0.3, NORMAL=0.3 is a guess, not spam
         */
        public const float MERGED_THRESHOLD = 0.75;

        /**
         * Judges the answers
         *
         * @param SystemOneResponse $response The answers
         * @param float $threshold The probability needed to act
         * @param string $prefix Optional. The prefix of the question names
         * @return Verdict The verdict, its details hold the flag (a ClassificationFlag), the incident type (an
         *                 IncidentType) and the strongest signal of spam (null if the signals were not asked)
         */
        public static function judge(SystemOneResponse $response, float $threshold, string $prefix=''): Verdict
        {
            $classification = $response->choice($prefix . ClassificationQuestions::CLASSIFICATION);
            if($classification === null)
            {
                return new Verdict('NONE', 0.0, false, 'the model did not answer the classification');
            }

            $reversed = $response->choice($prefix . ClassificationQuestions::CLASSIFICATION_REVERSED);
            $probabilities = [];
            foreach(ClassificationFlag::cases() as $flag)
            {
                $probabilities[$flag->value] = $reversed !== null
                    ? ($classification->getProbability($flag->value) + $reversed->getProbability($flag->value)) / 2
                    : $classification->getProbability($flag->value);
            }

            arsort($probabilities);
            $top = ClassificationFlag::from((string)array_key_first($probabilities));
            $spamProbability = $probabilities[ClassificationFlag::SUSPICIOUS->value] + $probabilities[ClassificationFlag::MALICIOUS->value];
            // The signals are only missing when a model with a small context was only asked the classification
            $signals = array_filter(ClassificationQuestions::SPAM_SIGNALS, fn(string $name) => $response->has($prefix . $name));
            $signal = count($signals) > 0 ? max(array_map(fn(string $name) => $response->noul($prefix . $name)->getNoul(), $signals)) : null;
            $details = [
                'signal' => $signal,
                'probabilities' => $probabilities,
                'incident_type' => self::getIncidentType($response, $prefix),
            ];

            $flag = $top;
            $probability = $probabilities[$top->value];
            if($top !== ClassificationFlag::NORMAL && $probabilities[ClassificationFlag::MALICIOUS->value] < $threshold && $spamProbability >= max($threshold, self::MERGED_THRESHOLD))
            {
                // Probably spam without being sure which kind, eg; MALICIOUS=0.45, SUSPICIOUS=0.35
                $flag = ClassificationFlag::SUSPICIOUS;
                $probability = $spamProbability;
            }

            $details['flag'] = $flag;
            if($reversed !== null && $reversed->getChoice() !== $classification->getChoice())
            {
                return new Verdict($flag->value, $probability, false, 'the answer changed with the order of the options', $details);
            }

            if($flag === ClassificationFlag::NORMAL && $response->has($prefix . ClassificationQuestions::ADDRESSES_REVIEWER)
                && $response->noul($prefix . ClassificationQuestions::ADDRESSES_REVIEWER)->getNoul() >= self::INJECTION)
            {
                return new Verdict($flag->value, $probability, false, 'the content addresses whoever reviews it', $details);
            }

            if($flag === ClassificationFlag::NORMAL && $signal !== null && $signal >= self::SIGNAL_YES)
            {
                return new Verdict($flag->value, $probability, false, 'classified NORMAL although it shows a sign of spam', $details);
            }

            if($flag === ClassificationFlag::MALICIOUS && $signal !== null && $signal < self::SIGNAL_NO)
            {
                return new Verdict($flag->value, $probability, false, 'classified MALICIOUS without any sign of spam', $details);
            }

            if($probability < $threshold)
            {
                return new Verdict($flag->value, $probability, false, sprintf('%.2f is below the threshold of %.2f', $probability, $threshold), $details);
            }

            return new Verdict($flag->value, $probability, true, 'confident', $details);
        }

        /**
         * Returns the incident type the model chose, SPAM when it wasn't asked or isn't sure
         *
         * @param SystemOneResponse $response The answers
         * @param string $prefix The prefix of the question names
         * @return IncidentType The incident type
         */
        public static function getIncidentType(SystemOneResponse $response, string $prefix=''): IncidentType
        {
            $answer = $response->choice($prefix . ClassificationQuestions::INCIDENT_TYPE);
            if($answer === null || $answer->getProbability($answer->getChoice()) < 0.5)
            {
                return IncidentType::SPAM;
            }

            return IncidentType::tryFrom($answer->getChoice()) ?? IncidentType::SPAM;
        }
    }
