<?php

    namespace FederationJevGuard\Classes\Questions;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\QuestionSet;
    use FederationJevGuard\Interfaces\QuestionInterface;
    use FederationJevGuard\Objects\SystemOne\ChoiceQuestion;
    use FederationLib\Enums\IncidentType;

    /**
     * The questions content is classified with, as NORMAL, SUSPICIOUS or MALICIOUS: the classification itself (asked in
     * both orders of its options) and the narrow signals the classification has to agree with. Only spam and what comes
     * with it (scams, phishing, malware) is judged, never politics, opinions, rudeness or sexual conversation. The wording
     * is in the question file, see QuestionSet
     */
    class ClassificationQuestions
    {
        public const string CLASSIFICATION = 'classification';
        public const string CLASSIFICATION_REVERSED = 'classification_reversed';
        public const string PROMOTION = 'promotion';
        public const string PAID_OFFER = 'paid_offer';
        public const string SCAM = 'scam_or_phishing';
        public const string MALWARE = 'malware_lure';
        public const string ADDRESSES_REVIEWER = 'addresses_reviewer';
        public const string INCIDENT_TYPE = 'incident_type';

        /**
         * The signals of spam, a spam classification needs at least one of them
         */
        public const array SPAM_SIGNALS = [self::PROMOTION, self::PAID_OFFER, self::SCAM, self::MALWARE];

        /**
         * Returns the questions
         *
         * @param string $path The path of the content in the state, eg; `messages` or `messages[0]`
         * @param IncidentType[] $concerns The concerns the incident type is chosen from, none to leave the question out
         * @param string $prefix Optional. A prefix of the question names, to ask about several contents in one request
         * @param bool|null $compact Optional. True to only ask the classification, for models with a small context; the
         *                           configured context decides when null
         * @return array<string, QuestionInterface> The questions by name
         */
        public static function build(string $path, array $concerns=[], string $prefix='', ?bool $compact=null): array
        {
            $set = QuestionSet::get();
            $placeholders = ['path' => $path];
            $classification = $set->choice(QuestionSet::CLASSIFICATION, self::CLASSIFICATION, $placeholders);

            if($compact ?? Configuration::getSystemOneConfiguration()->isCompact())
            {
                return [$prefix . self::CLASSIFICATION => $classification];
            }

            $questions = [
                $prefix . self::CLASSIFICATION => $classification,
                $prefix . self::CLASSIFICATION_REVERSED => $classification->reversed(),
            ];

            foreach(array_merge(self::SPAM_SIGNALS, [self::ADDRESSES_REVIEWER]) as $name)
            {
                $questions[$prefix . $name] = $set->noul(QuestionSet::CLASSIFICATION, $name, $placeholders);
            }

            $incidentType = self::incidentType($path, $concerns);
            if($incidentType !== null)
            {
                $questions[$prefix . self::INCIDENT_TYPE] = $incidentType;
            }

            return $questions;
        }

        /**
         * Returns the question that chooses the concern of abusive content, the incident type of its records
         *
         * @param string $path The path of the content in the state
         * @param IncidentType[] $concerns The concerns to choose from
         * @return ChoiceQuestion|null The question, null without concerns
         */
        public static function incidentType(string $path, array $concerns): ?ChoiceQuestion
        {
            if(count($concerns) === 0)
            {
                return null;
            }

            $criteria = [];
            foreach($concerns as $concern)
            {
                $criteria[$concern->value] = self::describeConcern($concern);
            }

            return QuestionSet::get()->choice(QuestionSet::CLASSIFICATION, self::INCIDENT_TYPE, ['path' => $path], $criteria);
        }

        /**
         * Describes a concern as an option of the incident type
         *
         * @param IncidentType $concern The concern
         * @return string The description
         */
        public static function describeConcern(IncidentType $concern): string
        {
            return QuestionSet::get()->describeConcern($concern);
        }
    }
