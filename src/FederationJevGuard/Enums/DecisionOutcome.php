<?php

    namespace FederationJevGuard\Enums;

    enum DecisionOutcome : string
    {
        /**
         * The decision was confident and acted on, eg; the report was closed or the entity blacklisted
         */
        case APPLIED = 'APPLIED';

        /**
         * The decision was confident and would have been acted on, the plugin is in dry run
         */
        case PREVIEWED = 'PREVIEWED';

        /**
         * The model leaned one way but not confidently enough to act, the subject is left to the operators
         */
        case UNCERTAIN = 'UNCERTAIN';

        /**
         * The model confidently decided that nothing should be done, eg; an entity should not be blacklisted
         */
        case DECLINED = 'DECLINED';

        /**
         * An operator lifted or deleted a blacklist record of the entity, the plugin only considers what happens after
         */
        case SUPPRESSED = 'SUPPRESSED';

        /**
         * Returns True if the outcome is a decision of the model rather than a record of what an operator did
         *
         * @return bool True for a decision of the model
         */
        public function isDecision(): bool
        {
            return $this !== self::SUPPRESSED;
        }
    }
