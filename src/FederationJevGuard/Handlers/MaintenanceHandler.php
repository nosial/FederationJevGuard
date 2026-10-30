<?php

    namespace FederationJevGuard\Handlers;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\Configuration\ReviewConfiguration;
    use FederationJevGuard\Classes\Logger;
    use FederationJevGuard\Classes\Managers\DecisionManager;
    use FederationJevGuard\Classes\Managers\QueueManager;
    use FederationJevGuard\Classes\Managers\UsageManager;
    use FederationJevGuard\Classes\PluginOperator;
    use FederationJevGuard\Classes\QueueWorker;
    use FederationJevGuard\Enums\SubjectType;
    use FederationJevGuard\Exceptions\ReviewException;
    use FederationJevGuard\FederationJevGuard;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Enums\Categories\EvidenceCategory;
    use FederationLib\Enums\Categories\ReportCategory;
    use FederationLib\Enums\OrderType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\MaintenanceEventHandlerInterface;
    use FederationLib\Objects\Plugin\Maintenance;
    use InvalidArgumentException;

    class MaintenanceHandler implements MaintenanceEventHandlerInterface
    {
        /**
         * The page size of the listings the records not reviewed yet are found in
         */
        private const int PAGE_SIZE = 100;

        /**
         * The most entities with the lowest reputation that are checked for a blacklist record every run
         */
        private const int TOP_THREATS = 100;

        /**
         * @inheritDoc
         */
        public static function handleMaintenance(Maintenance $maintenance): void
        {
            if(!Configuration::isEnabled())
            {
                Logger::log()->debug('FederationJevGuard is disabled, skipping its maintenance');
                return;
            }

            try
            {
                // The operator may have been deleted or changed since the server started, a disabled operator stays
                // disabled
                PluginOperator::provision();
            }
            catch(ReviewException|DatabaseOperationException|InvalidArgumentException $e)
            {
                Logger::log()->error('Unable to repair the FederationJevGuard operator, the plugin is inactive until it is: ' . $e->getMessage(), $e);
                return;
            }

            if(!FederationJevGuard::isOperational())
            {
                Logger::log()->info('FederationJevGuard is inactive (its operator is disabled or the System One endpoint is not configured), skipping its maintenance');
                return;
            }

            try
            {
                if($maintenance->isCleaningEnabled())
                {
                    $purged = DecisionManager::purge(time() - ReviewConfiguration::DECISION_RETENTION);
                    UsageManager::purge(time() - ReviewConfiguration::DECISION_RETENTION);
                    if($purged > 0)
                    {
                        Logger::log()->info(sprintf('Removed %d decision(s) older than a year', $purged));
                    }
                }

                $queued = self::queueUnreviewed();
                $result = QueueWorker::drain(PHP_INT_MAX, ReviewConfiguration::MAINTENANCE_SECONDS);
                Logger::log()->info(sprintf(
                    'Maintenance: %d report(s), %d evidence record(s) and %d entities queued; %d reviewed, %d decided, %d acted on, %d failed%s; %d still queued',
                    $queued['reports'], $queued['evidence'], $queued['entities'],
                    $result['reviewed'], $result['decided'], $result['applied'], $result['failed'],
                    $result['busy'] ? ' (another process is reviewing the queue)' : '',
                    QueueManager::count()
                ));
            }
            catch(DatabaseOperationException|InvalidArgumentException $e)
            {
                Logger::log()->error('The FederationJevGuard maintenance failed, the next run tries again: ' . $e->getMessage(), $e);
            }
        }

        /**
         * Queues the records not reviewed yet: opened reports, unclassified evidence that belongs to no report, and the
         * entities that may need a blacklist record (the lowest reputations and the reported entities of recently
         * closed reports). A record that didn't change since its last decision is skipped by its reviewer without a
         * request to the model
         *
         * @return array{reports: int, evidence: int, entities: int} The number of records queued
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        private static function queueUnreviewed(): array
        {
            $queued = ['reports' => 0, 'evidence' => 0, 'entities' => 0];
            $pages = (int)ceil(ReviewConfiguration::MAINTENANCE_BATCH / self::PAGE_SIZE);

            if(Configuration::getReviewConfiguration()->isReportsEnabled())
            {
                for($page = 1; $page <= $pages; $page++)
                {
                    $reports = ReportManager::getReports(self::PAGE_SIZE, $page, ReportCategory::OPENED);
                    foreach($reports as $report)
                    {
                        QueueManager::enqueue(SubjectType::REPORT, $report->getUuid());
                        $queued['reports']++;
                    }

                    if(count($reports) < self::PAGE_SIZE)
                    {
                        break;
                    }
                }
            }

            if(Configuration::getReviewConfiguration()->isEvidenceEnabled())
            {
                for($page = 1; $page <= $pages; $page++)
                {
                    $evidence = EvidenceManager::getEvidenceRecords(self::PAGE_SIZE, $page, true, EvidenceCategory::UNCLASSIFIED);
                    foreach($evidence as $record)
                    {
                        if($record->getReport() === null)
                        {
                            QueueManager::enqueue(SubjectType::EVIDENCE, $record->getUuid());
                            $queued['evidence']++;
                        }
                    }

                    if(count($evidence) < self::PAGE_SIZE)
                    {
                        break;
                    }
                }
            }

            if(Configuration::getBlacklistConfiguration()->isEnabled())
            {
                $entities = [];
                foreach(EntitiesManager::getTopThreats(self::TOP_THREATS) as $entity)
                {
                    $entities[$entity->getUuid()] = true;
                }

                for($page = 1; $page <= $pages && count($entities) < ReviewConfiguration::MAINTENANCE_BATCH; $page++)
                {
                    $reports = ReportManager::getReports(self::PAGE_SIZE, $page, ReportCategory::CLOSED, 'UPDATED', OrderType::DESCENDING);
                    foreach($reports as $report)
                    {
                        if($report->getReportingEntity() !== null)
                        {
                            $entities[$report->getReportingEntity()] = true;
                        }
                    }

                    if(count($reports) < self::PAGE_SIZE)
                    {
                        break;
                    }
                }

                foreach(array_keys($entities) as $entity)
                {
                    QueueManager::enqueue(SubjectType::ENTITY, (string)$entity);
                    $queued['entities']++;
                }
            }

            return $queued;
        }
    }
