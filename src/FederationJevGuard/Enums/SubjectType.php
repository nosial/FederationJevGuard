<?php

    namespace FederationJevGuard\Enums;

    enum SubjectType : string
    {
        /**
         * An opened report, decided by the classification it's closed with
         */
        case REPORT = 'REPORT';

        /**
         * An unclassified evidence record that belongs to no report, decided by its classification
         */
        case EVIDENCE = 'EVIDENCE';

        /**
         * An entity, decided by whether it's blacklisted
         */
        case ENTITY = 'ENTITY';

        /**
         * An image attachment, described by the findings of the model, which the other reviews are given
         */
        case ATTACHMENT = 'ATTACHMENT';

        /**
         * Returns the subject type of a value, case-insensitive
         *
         * @param string $value The value
         * @return SubjectType|null The subject type, null if the value is not one
         */
        public static function tryFromCaseInsensitive(string $value): ?SubjectType
        {
            return self::tryFrom(strtoupper(trim($value)));
        }
    }
