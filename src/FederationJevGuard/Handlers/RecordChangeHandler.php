<?php

    namespace FederationJevGuard\Handlers;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\Configuration\ReviewConfiguration;
    use FederationJevGuard\Classes\ContextBuilder;
    use FederationJevGuard\Classes\Logger;
    use FederationJevGuard\Classes\Managers\DecisionManager;
    use FederationJevGuard\Classes\Managers\QueueManager;
    use FederationJevGuard\Classes\PluginOperator;
    use FederationJevGuard\Enums\DecisionOutcome;
    use FederationJevGuard\Enums\SubjectType;
    use FederationJevGuard\FederationJevGuard;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\RecordChangeEventHandlerInterface;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\FileAttachmentRecord;
    use FederationLib\Objects\Plugin\RecordChange;
    use FederationLib\Objects\ReportRecord;
    use InvalidArgumentException;

    class RecordChangeHandler implements RecordChangeEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleRecordChange(RecordChange $change): void
        {
            if(!FederationJevGuard::isOperational())
            {
                return;
            }

            try
            {
                self::handle($change);
            }
            catch(DatabaseOperationException|InvalidArgumentException $e)
            {
                Logger::log()->warning(sprintf('Unable to handle the %s change of %s: %s', $change->getType()->value, $change->getUuid(), $e->getMessage()), $e);
            }
        }

        /**
         * Handles a record change
         *
         * @param RecordChange $change The change
         * @return void
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        private static function handle(RecordChange $change): void
        {
            $reviews = Configuration::getReviewConfiguration();
            switch($change->getType())
            {
                case RecordChangeType::REPORT_CREATED:
                    if($reviews->isReportsEnabled())
                    {
                        // A report usually gets its evidence right after it's created
                        QueueManager::enqueue(SubjectType::REPORT, $change->getUuid(), ReviewConfiguration::SETTLE_DELAY);
                    }
                    break;

                case RecordChangeType::EVIDENCE_CREATED:
                    $evidence = $change->getRecord();
                    if($evidence instanceof EvidenceRecord)
                    {
                        self::queueEvidence($evidence);
                    }
                    break;

                case RecordChangeType::ATTACHMENT_CREATED:
                    $attachment = $change->getRecord();
                    if($attachment instanceof FileAttachmentRecord && $attachment->getEvidenceUuid() !== null)
                    {
                        if(Configuration::isImagesEnabled())
                        {
                            QueueManager::enqueue(SubjectType::ATTACHMENT, $attachment->getUuid());
                        }

                        $evidence = EvidenceManager::getEvidence($attachment->getEvidenceUuid());
                        if($evidence !== null)
                        {
                            self::queueEvidence($evidence);
                        }
                    }
                    break;

                case RecordChangeType::REPORT_CLOSED:
                    $report = $change->getRecord();
                    if($report instanceof ReportRecord && $report->getReportingEntity() !== null && Configuration::getBlacklistConfiguration()->isEnabled())
                    {
                        QueueManager::enqueue(SubjectType::ENTITY, $report->getReportingEntity(), ReviewConfiguration::SETTLE_DELAY);
                    }
                    break;

                case RecordChangeType::ENTITY_UPDATED:
                    $entity = $change->getRecord();
                    if($entity instanceof EntityRecord && $entity->isWhitelisted())
                    {
                        self::liftWhitelisted($entity);
                    }
                    break;

                case RecordChangeType::BLACKLIST_LIFTED:
                    $blacklist = $change->getRecord();
                    if($blacklist instanceof BlacklistRecord && !PluginOperator::isPluginOperator($blacklist->getLiftedBy()))
                    {
                        self::suppress($blacklist->getEntityUuid(), $blacklist->getUuid(), 'an operator lifted a blacklist record of the entity');
                    }
                    break;

                case RecordChangeType::BLACKLIST_DELETED:
                    // The record is gone, only a blacklist record the plugin created can be traced to its entity
                    $decision = DecisionManager::getDecisionByRecord($change->getUuid());
                    if($decision !== null && $decision->getEntity() !== null)
                    {
                        self::suppress($decision->getEntity(), $change->getUuid(), 'an operator deleted the blacklist record of the plugin');
                    }
                    break;

                default:
                    // ENTITY_DELETED: the plugin's decisions about the entity are deleted with it (a foreign key), its
                    // queued reviews find nothing to review
                    break;
            }
        }

        /**
         * Queues the review of new evidence: its report, or the evidence itself when it belongs to no report
         *
         * @param EvidenceRecord $evidence The evidence record
         * @return void
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        private static function queueEvidence(EvidenceRecord $evidence): void
        {
            $reviews = Configuration::getReviewConfiguration();
            if($evidence->getReport() !== null)
            {
                if($reviews->isReportsEnabled())
                {
                    QueueManager::enqueue(SubjectType::REPORT, $evidence->getReport(), ReviewConfiguration::SETTLE_DELAY);
                }
            }
            elseif($reviews->isEvidenceEnabled() && $evidence->getClassificationFlag() === null)
            {
                QueueManager::enqueue(SubjectType::EVIDENCE, $evidence->getUuid(), ReviewConfiguration::SETTLE_DELAY);
            }
        }

        /**
         * Lifts the active blacklist records the plugin created for a whitelisted entity, the plugin never lifts
         * another operator's
         *
         * @param EntityRecord $entity The whitelisted entity
         * @return void
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        private static function liftWhitelisted(EntityRecord $entity): void
        {
            $operator = PluginOperator::getUuid();
            foreach(BlacklistManager::getEntriesByEntity($entity->getUuid(), 100) as $blacklist)
            {
                if(ContextBuilder::isActive($blacklist) && PluginOperator::isPluginBlacklist($blacklist))
                {
                    // Recorded like the Lift Blacklist method records it
                    BlacklistManager::liftBlacklistRecord($blacklist->getUuid(), $operator);
                    AuditLogManager::createEntry(AuditLogType::BLACKLIST_LIFTED, sprintf('Blacklist record lifted by operator %s, the entity was whitelisted', PluginOperator::NAME),
                        $operator, $entity->getUuid(), $blacklist->getUuid());
                    Logger::log()->info(sprintf('Lifted the blacklist record %s of %s, the entity was whitelisted', $blacklist->getUuid(), $entity->getLoggableAddress()));
                }
            }
        }

        /**
         * Records that an operator lifted or deleted a blacklist record of an entity, only what happens afterwards counts
         * towards a new blacklist record of the plugin
         *
         * @param string $entity The UUID of the entity
         * @param string $blacklist The UUID of the blacklist record
         * @param string $reason What the operator did
         * @return void
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a UUID is invalid
         */
        private static function suppress(string $entity, string $blacklist, string $reason): void
        {
            DecisionManager::record(SubjectType::ENTITY, $entity, $entity, DecisionOutcome::SUPPRESSED, reason: $reason, record: $blacklist);
            Logger::log()->info(sprintf('The blacklist record %s of %s was lifted or deleted by an operator, the plugin only considers what happens after', $blacklist, $entity));
        }
    }
