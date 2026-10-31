<?php

    namespace FederationJevGuard\Classes\Judges;

    use FederationJevGuard\Classes\Questions\BlacklistQuestions;
    use FederationJevGuard\Objects\SystemOne\SystemOneResponse;
    use FederationJevGuard\Objects\Verdict;
    use FederationLib\Enums\IncidentType;

    class BlacklistJudge
    {
        public const float THRESHOLD = 0.8;
        public const float SIGNAL_YES = 0.7;
        public const float SIGNAL_NO = 0.3;
        public const float SEVERE = 0.75;

        /**
         * Judges the answers
         *
         * @param SystemOneResponse $response The answers
         * @param int $duration The duration of a first blacklist record, in seconds
         * @param int $maxDuration The longest blacklist record, in seconds
         * @param int $previousBlacklists The number of blacklist records the plugin created for the entity before
         * @return Verdict The verdict (BLACKLIST or DO_NOT_BLACKLIST), its details hold the incident type (an IncidentType) and the duration in seconds
         */
        public static function judge(SystemOneResponse $response, int $duration, int $maxDuration, int $previousBlacklists): Verdict
        {
            $decision = $response->choice(BlacklistQuestions::BLACKLIST);
            if($decision === null)
            {
                return new Verdict('NONE', 0.0, false, 'the model did not answer whether to blacklist');
            }

            $reversed = $response->choice(BlacklistQuestions::BLACKLIST_REVERSED);
            $blacklist = $reversed !== null
                ? ($decision->getProbability(BlacklistQuestions::OPTION_BLACKLIST) + $reversed->getProbability(BlacklistQuestions::OPTION_BLACKLIST)) / 2
                : $decision->getProbability(BlacklistQuestions::OPTION_BLACKLIST);
            $declined = $reversed !== null
                ? ($decision->getProbability(BlacklistQuestions::OPTION_DO_NOT_BLACKLIST) + $reversed->getProbability(BlacklistQuestions::OPTION_DO_NOT_BLACKLIST)) / 2
                : $decision->getProbability(BlacklistQuestions::OPTION_DO_NOT_BLACKLIST);

            // The checks a model with a small context was not asked are left out, the pattern is always asked
            $repeated = $response->noul(BlacklistQuestions::REPEATED)->getNoul();
            $mistaken = $response->has(BlacklistQuestions::MISTAKEN) ? $response->noul(BlacklistQuestions::MISTAKEN)->getNoul() : 0.0;
            $compromised = $response->has(BlacklistQuestions::COMPROMISED) ? $response->noul(BlacklistQuestions::COMPROMISED)->getNoul() : 0.0;
            $severity = $response->score(BlacklistQuestions::SEVERITY);
            $normalizedSeverity = $severity !== null ? min(1.0, $severity->getScore() / 2) : 0.0;

            $details = [
                'incident_type' => ClassificationJudge::getIncidentType($response),
                'duration' => self::getDuration($duration, $maxDuration, $previousBlacklists, $normalizedSeverity, $compromised),
                'repeated' => $repeated,
                'mistaken' => $mistaken,
                'compromised' => $compromised,
                'severity' => $normalizedSeverity,
            ];

            if($reversed !== null && $reversed->getChoice() !== $decision->getChoice())
            {
                return new Verdict($blacklist >= $declined ? BlacklistQuestions::OPTION_BLACKLIST : BlacklistQuestions::OPTION_DO_NOT_BLACKLIST,
                    max($blacklist, $declined), false, 'the answer changed with the order of the options', $details);
            }

            if($declined >= self::THRESHOLD)
            {
                return new Verdict(BlacklistQuestions::OPTION_DO_NOT_BLACKLIST, $declined, true, 'confident', $details);
            }

            if($blacklist < self::THRESHOLD)
            {
                return new Verdict(BlacklistQuestions::OPTION_BLACKLIST, $blacklist, false, sprintf('%.2f is below the threshold of %.2f', $blacklist, self::THRESHOLD), $details);
            }

            if($response->has(BlacklistQuestions::ADDRESSES_REVIEWER) && $response->noul(BlacklistQuestions::ADDRESSES_REVIEWER)->getNoul() >= ClassificationJudge::INJECTION)
            {
                return new Verdict(BlacklistQuestions::OPTION_BLACKLIST, $blacklist, false, 'the evidence addresses whoever reviews it', $details);
            }

            if($repeated < self::SIGNAL_YES)
            {
                return new Verdict(BlacklistQuestions::OPTION_BLACKLIST, $blacklist, false, 'the reports show no clear pattern', $details);
            }

            if($mistaken >= self::SIGNAL_NO)
            {
                return new Verdict(BlacklistQuestions::OPTION_BLACKLIST, $blacklist, false, 'the earlier classifications may be mistaken', $details);
            }

            return new Verdict(BlacklistQuestions::OPTION_BLACKLIST, $blacklist, true, 'confident', $details);
        }

        /**
         * Returns how long the blacklist record lasts: twice as long as the plugin's previous one for the entity, and
         * twice as long again for harmful abuse, but never longer than the maximum. An account that looks taken over
         * only gets the first duration
         *
         * @param int $duration The duration of a first blacklist record, in seconds
         * @param int $maxDuration The longest blacklist record, in seconds
         * @param int $previousBlacklists The number of blacklist records the plugin created for the entity before
         * @param float $severity The normalized severity, 0 to 1
         * @param float $compromised The probability that the account was taken over
         * @return int The duration in seconds
         */
        public static function getDuration(int $duration, int $maxDuration, int $previousBlacklists, float $severity, float $compromised): int
        {
            if($compromised >= self::SIGNAL_YES)
            {
                return min($duration, $maxDuration);
            }

            $factor = 2 ** min(10, max(0, $previousBlacklists)) * ($severity >= self::SEVERE ? 2 : 1);
            return (int)min($maxDuration, $duration * $factor);
        }
    }
