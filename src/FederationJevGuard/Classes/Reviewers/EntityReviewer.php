<?php

    namespace FederationJevGuard\Classes\Reviewers;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\Configuration\BlacklistConfiguration;
    use FederationJevGuard\Classes\ContextBuilder;
    use FederationJevGuard\Classes\DatabaseLock;
    use FederationJevGuard\Classes\Evaluator;
    use FederationJevGuard\Classes\ImageCollector;
    use FederationJevGuard\Classes\Judges\BlacklistJudge;
    use FederationJevGuard\Classes\Logger;
    use FederationJevGuard\Classes\Managers\DecisionManager;
    use FederationJevGuard\Classes\PluginOperator;
    use FederationJevGuard\Classes\Questions\BlacklistQuestions;
    use FederationJevGuard\Enums\DecisionOutcome;
    use FederationJevGuard\Enums\SubjectType;
    use FederationJevGuard\Exceptions\BudgetExceededException;
    use FederationJevGuard\Exceptions\ReviewException;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\Objects\DecisionRecord;
    use FederationJevGuard\Objects\Limits;
    use FederationLib\Classes\Configuration as FederationConfiguration;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\Categories\ReportCategory;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ReportRecord;
    use InvalidArgumentException;

    class EntityReviewer
    {
        /**
         * The most evidence records of each report that are read
         */
        public const int MAX_EVIDENCE = 25;

        /**
         * Reviews an entity
         *
         * @param string $uuid The UUID of the entity
         * @return DecisionRecord|null The decision, null if the entity doesn't qualify for a review
         * @throws SystemOneException If the request to the System One endpoint failed
         * @throws BudgetExceededException If the daily token budget is used up
         * @throws ReviewException If the plugin operator is unavailable
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function review(string $uuid): ?DecisionRecord
        {
            $configuration = Configuration::getBlacklistConfiguration();
            $entity = EntitiesManager::getEntityByUuid($uuid);
            if($entity === null || $entity->isWhitelisted() || ContextBuilder::isBot($entity))
            {
                return null;
            }

            $blacklists = BlacklistManager::getEntriesByEntity($uuid, 100, 1, true);
            if(count(array_filter($blacklists, fn(BlacklistRecord $record) => ContextBuilder::isActive($record))) > 0)
            {
                return null;
            }

            $since = self::getResetTime($uuid, $blacklists);
            $cases = self::getCases($uuid, $since);
            $counts = self::count($cases);
            $spam = $counts[ClassificationFlag::SUSPICIOUS->value] + $counts[ClassificationFlag::MALICIOUS->value];
            $qualifies = ($spam >= $configuration->getMinReports() && $counts[ClassificationFlag::MALICIOUS->value] >= 1 && $spam > $counts[ClassificationFlag::NORMAL->value])
                || ($entity->getReputation() <= BlacklistConfiguration::CANDIDATE_REPUTATION && $spam >= 1);
            if(!$qualifies)
            {
                return null;
            }

            $fingerprint = hash('sha256', (string)json_encode([$uuid, $since, array_map(fn(array $case) => [$case['report']->getUuid(), $case['classification']?->value], $cases)]));
            $latest = DecisionManager::getLatestDecision(SubjectType::ENTITY, $uuid);
            if($latest !== null && $latest->getInputHash() === $fingerprint)
            {
                return null;
            }

            $previous = count(array_filter($blacklists, fn(BlacklistRecord $record) => PluginOperator::isPluginBlacklist($record)));
            $days = max(1, (int)round(BlacklistJudge::getDuration($configuration->getDuration(), $configuration->getMaxDuration(), $previous, 0.0, 0.0) / 86400));
            $profile = ContextBuilder::entityProfile($entity, new Limits());
            $summary = ContextBuilder::compact([
                'reports_considered' => count($cases),
                'by_classification' => array_filter($counts),
                'considered_since' => $since > 0 ? ContextBuilder::date($since) . ' (an operator reviewed everything before)' : null,
                'earlier_blacklists_by_this_reviewer' => $previous,
            ]);

            $evaluation = Evaluator::evaluate(function(Limits $limits, ImageCollector $images) use ($entity, $profile, $summary, $cases): array
            {
                return [
                    'entity' => $profile,
                    'summary' => $summary,
                    'reports' => self::renderCases($entity, $cases, $limits, $images),
                ];
            }, BlacklistQuestions::build($days, Configuration::getConcerns()));

            $verdict = BlacklistJudge::judge($evaluation->getResponse(), $configuration->getDuration(), $configuration->getMaxDuration(), $previous);
            $outcome = DecisionOutcome::UNCERTAIN;
            $reason = $verdict->getReason();
            $record = null;

            if($verdict->isConfident() && $verdict->getVerdict() === BlacklistQuestions::OPTION_DO_NOT_BLACKLIST)
            {
                $outcome = DecisionOutcome::DECLINED;
            }
            elseif($verdict->isConfident())
            {
                if(Configuration::isDryRun())
                {
                    $outcome = DecisionOutcome::PREVIEWED;
                }
                else
                {
                    $record = self::blacklist($entity, $verdict->getDetail('incident_type', IncidentType::SPAM), (int)$verdict->getDetail('duration'), $cases, $verdict->getProbability(), $evaluation->getResponse()->getModel());
                    $outcome = $record !== null ? DecisionOutcome::APPLIED : DecisionOutcome::UNCERTAIN;
                    $reason = $record !== null ? sprintf('blacklisted for %d day(s)', round((int)$verdict->getDetail('duration') / 86400)) : 'the entity was blacklisted or whitelisted in the meantime';
                }
            }

            $decision = DecisionManager::record(
                subjectType: SubjectType::ENTITY,
                subject: $uuid,
                entity: $uuid,
                outcome: $outcome,
                verdict: $verdict->getVerdict(),
                probability: $verdict->getProbability(),
                reason: $reason,
                record: $record,
                inputHash: $fingerprint,
                model: $evaluation->getResponse()->getModel(),
                answers: $evaluation->getResponse()->getAnswers(),
                inputTokens: $evaluation->getResponse()->getInputTokens()
            );

            Logger::log()->info(sprintf('Entity %s: %s with a probability of %.2f, %s (%s)', $entity->getLoggableAddress(), $verdict->getVerdict(), $verdict->getProbability(), strtolower($outcome->value), $reason));
            return DecisionManager::getDecision($decision);
        }

        /**
         * Returns when the reports of an entity start to count: when an operator last lifted or deleted one of its
         * blacklist records (as recorded by the plugin), or created the latest blacklist record an operator lifted
         *
         * @param string $uuid The UUID of the entity
         * @param BlacklistRecord[] $blacklists The blacklist records of the entity
         * @return int The Unix timestamp, 0 if every report counts
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function getResetTime(string $uuid, array $blacklists): int
        {
            $since = DecisionManager::getLastSuppression($uuid) ?? 0;
            foreach($blacklists as $record)
            {
                if($record->isLifted() && !PluginOperator::isPluginOperator($record->getLiftedBy()))
                {
                    $since = max($since, $record->getCreated());
                }
            }

            return $since;
        }

        /**
         * Returns the closed reports of an entity created since a time, the most recent first, with their evidence and
         * classification
         *
         * @param string $uuid The UUID of the entity
         * @param int $since The Unix timestamp from which reports count
         * @return array<int, array{report: ReportRecord, evidence: array, classification: ClassificationFlag|null}> The reports
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        private static function getCases(string $uuid, int $since): array
        {
            $cases = [];
            foreach(ReportManager::getReportsByReportingEntity($uuid, BlacklistConfiguration::MAX_REPORTS * 2, 1, ReportCategory::CLOSED) as $report)
            {
                if($report->getCreated() <= $since)
                {
                    continue;
                }

                $evidence = EvidenceManager::getEvidenceByReport($report->getUuid(), self::MAX_EVIDENCE, includeConfidential: true);
                $cases[] = ['report' => $report, 'evidence' => $evidence, 'classification' => ContextBuilder::getReportClassification($evidence)];
                if(count($cases) >= BlacklistConfiguration::MAX_REPORTS)
                {
                    break;
                }
            }

            return $cases;
        }

        /**
         * Counts the reports by classification
         *
         * @param array $cases The reports
         * @return array<string, int> The number of reports by classification, unclassified ones under 'unclassified'
         */
        private static function count(array $cases): array
        {
            $counts = [ClassificationFlag::NORMAL->value => 0, ClassificationFlag::SUSPICIOUS->value => 0, ClassificationFlag::MALICIOUS->value => 0, 'unclassified' => 0];
            foreach($cases as $case)
            {
                $counts[$case['classification']?->value ?? 'unclassified']++;
            }

            return $counts;
        }

        /**
         * Renders the reports of the state within its limits, the most recent first
         *
         * @param EntityRecord $entity The entity
         * @param array $cases The reports
         * @param Limits $limits The limits of the state
         * @param ImageCollector $images The images of the state
         * @return array The reports
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        private static function renderCases(EntityRecord $entity, array $cases, Limits $limits, ImageCollector $images): array
        {
            $evidenceLimits = new Limits($limits->getTextLength(), max(1, min(10, intdiv($limits->getMaxItems(), 4))), $limits->getMaxImages());
            $reports = [];
            foreach(array_slice($cases, 0, $limits->getMaxItems()) as $case)
            {
                $messages = ContextBuilder::messages($case['evidence'], $entity->getUuid(), $evidenceLimits, $images);
                $reports[] = ContextBuilder::compact(ContextBuilder::report($case['report'], $limits) + [
                    'classification' => $case['classification']?->value ?? 'unclassified',
                    'messages' => $messages['items'],
                    'messages_left_out' => $messages['left_out'] ?: null,
                ]);
            }

            return $reports;
        }

        /**
         * Blacklists the entity temporarily, after checking under a lock that it's still not blacklisted nor
         * whitelisted. The blacklist record refers to the entity's most recent MALICIOUS report, or its most recent
         * report otherwise, and lasts at least the server's minimum blacklist time
         *
         * @param EntityRecord $entity The entity
         * @param IncidentType $type The incident type of the blacklist record
         * @param int $duration How long the blacklist record lasts, in seconds
         * @param array $cases The reports the decision was made on
         * @param float $probability The probability of the decision
         * @param string $model The model that decided
         * @return string|null The UUID of the blacklist record, null if the entity changed in the meantime
         * @throws ReviewException If the plugin operator is unavailable or the entity is being reviewed elsewhere
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If FederationLib rejects the blacklist record
         */
        private static function blacklist(EntityRecord $entity, IncidentType $type, int $duration, array $cases, float $probability, string $model): ?string
        {
            $lock = DatabaseLock::getSubjectLock(SubjectType::ENTITY->value, $entity->getUuid());
            if(!DatabaseLock::acquire($lock, 10))
            {
                throw new ReviewException(sprintf('The entity %s is being reviewed elsewhere', $entity->getUuid()));
            }

            try
            {
                $operator = PluginOperator::get();
                $current = EntitiesManager::getEntityByUuid($entity->getUuid());
                if($current === null || $current->isWhitelisted())
                {
                    return null;
                }

                if (array_any(BlacklistManager::getEntriesByEntity($entity->getUuid(), 100), fn($record) => ContextBuilder::isActive($record)))
                {
                    return null;
                }

                $report = null;
                foreach($cases as $case)
                {
                    if($case['classification'] === ClassificationFlag::MALICIOUS)
                    {
                        $report = $case['report'];
                        break;
                    }
                }

                $report ??= $cases[0]['report'] ?? null;
                $expires = time() + max($duration, FederationConfiguration::getServerConfiguration()->getMinBlacklistTime());
                $blacklistUuid = BlacklistManager::blacklistEntity($entity->getUuid(), $operator->getUuid(), $type, $expires, $report?->getUuid());
                AuditLogManager::createEntry(AuditLogType::ENTITY_BLACKLISTED, sprintf(
                    'Entity %s blacklisted for %s until %s by %s: the model %s decided to blacklist it with a probability of %.2f',
                    $current->getLoggableAddress(), $type->value, gmdate('Y-m-d H:i', $expires) . ' UTC', $operator->getName(), $model, $probability
                ), $operator->getUuid(), $entity->getUuid(), $blacklistUuid);

                return $blacklistUuid;
            }
            finally
            {
                DatabaseLock::releaseQuietly($lock);
            }
        }
    }
