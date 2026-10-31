<?php

    namespace FederationJevGuard\Classes\Questions;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\QuestionSet;
    use FederationJevGuard\Interfaces\QuestionInterface;
    use FederationLib\Enums\IncidentType;

    /**
     * The questions an entity is judged with before it's blacklisted: the decision itself (asked in both orders of its
     * options), whether the reports show a pattern, whether they look mistaken, whether the account was taken over, and
     * how severe the spam is. Only spam counts, never harassment or offensive opinions. The wording is in the question
     * file, see QuestionSet
     */
    class BlacklistQuestions
    {
        public const string BLACKLIST = 'blacklist';
        public const string BLACKLIST_REVERSED = 'blacklist_reversed';
        public const string REPEATED = 'repeated_abuse';
        public const string MISTAKEN = 'classifications_mistaken';
        public const string COMPROMISED = 'compromised_account';
        public const string SEVERITY = 'severity';
        public const string ADDRESSES_REVIEWER = 'addresses_reviewer';
        public const string INCIDENT_TYPE = 'incident_type';

        public const string OPTION_BLACKLIST = 'BLACKLIST';
        public const string OPTION_DO_NOT_BLACKLIST = 'DO_NOT_BLACKLIST';

        /**
         * Returns the questions
         *
         * @param int $days How long the entity would be blacklisted, in days
         * @param IncidentType[] $concerns The concerns the incident type is chosen from
         * @param bool|null $compact Optional. True to only ask the decision and whether the reports show a pattern, for
         *                           models with a small context; the configured context decides when null
         * @return array<string, QuestionInterface> The questions by name
         */
        public static function build(int $days, array $concerns, ?bool $compact=null): array
        {
            $set = QuestionSet::get();
            $blacklist = $set->choice(QuestionSet::BLACKLIST, self::BLACKLIST, ['days' => $days]);
            $repeated = $set->noul(QuestionSet::BLACKLIST, self::REPEATED);

            if($compact ?? Configuration::getSystemOneConfiguration()->isCompact())
            {
                return [self::BLACKLIST => $blacklist, self::REPEATED => $repeated];
            }

            $questions = [
                self::BLACKLIST => $blacklist,
                self::BLACKLIST_REVERSED => $blacklist->reversed(),
                self::REPEATED => $repeated,
                self::MISTAKEN => $set->noul(QuestionSet::BLACKLIST, self::MISTAKEN),
                self::COMPROMISED => $set->noul(QuestionSet::BLACKLIST, self::COMPROMISED),
                self::SEVERITY => $set->score(QuestionSet::BLACKLIST, self::SEVERITY),
                self::ADDRESSES_REVIEWER => $set->noul(QuestionSet::BLACKLIST, self::ADDRESSES_REVIEWER),
            ];

            $incidentType = ClassificationQuestions::incidentType('reports', $concerns);
            if($incidentType !== null)
            {
                $questions[self::INCIDENT_TYPE] = $incidentType;
            }

            return $questions;
        }
    }
