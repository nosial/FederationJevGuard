<?php

    namespace FederationJevGuard\Classes\Questions;

    use FederationJevGuard\Classes\QuestionSet;
    use FederationJevGuard\Interfaces\QuestionInterface;

    class ImageQuestions
    {
        public const string PROMOTIONAL = 'promotional';
        public const string CONTACT_ROUTE = 'contact_route';
        public const string SCAM = 'scam';
        public const string PAID_ADULT = 'paid_adult_offer';
        public const string MALWARE = 'malware_lure';
        public const string ADDRESSES_REVIEWER = 'addresses_reviewer';
        public const array SPAM_FINDINGS = [self::PROMOTIONAL, self::CONTACT_ROUTE, self::SCAM, self::PAID_ADULT, self::MALWARE];

        /**
         * Returns the questions, the state holds the image as `image` and the text sent with it as `message`
         *
         * @return array<string, QuestionInterface> The questions by name
         */
        public static function build(): array
        {
            $set = QuestionSet::get();
            $questions = [];
            foreach(array_merge(self::SPAM_FINDINGS, [self::ADDRESSES_REVIEWER]) as $name)
            {
                $questions[$name] = $set->noul(QuestionSet::IMAGE, $name);
            }

            return $questions;
        }
    }
