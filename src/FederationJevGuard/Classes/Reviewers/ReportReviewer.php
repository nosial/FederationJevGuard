<?php

    namespace FederationJevGuard\Classes\Reviewers;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\ContextBuilder;
    use FederationJevGuard\Classes\DatabaseLock;
    use FederationJevGuard\Classes\Evaluator;
    use FederationJevGuard\Classes\ImageCollector;
    use FederationJevGuard\Classes\Judges\ClassificationJudge;
    use FederationJevGuard\Classes\Logger;
    use FederationJevGuard\Classes\Managers\DecisionManager;
    use FederationJevGuard\Classes\Managers\QueueManager;
    use FederationJevGuard\Classes\PluginOperator;
    use FederationJevGuard\Classes\Questions\ClassificationQuestions;
    use FederationJevGuard\Classes\Configuration\ReviewConfiguration;
    use FederationJevGuard\Enums\DecisionOutcome;
    use FederationJevGuard\Enums\SubjectType;
    use FederationJevGuard\Exceptions\BudgetExceededException;
    use FederationJevGuard\Exceptions\ReviewException;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\Objects\DecisionRecord;
    use FederationJevGuard\Objects\Limits;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\ReportRecord;
    use InvalidArgumentException;

    class ReportReviewer
    {
        /**
         * The most evidence records of a report that are read
         */
        public const int MAX_EVIDENCE = 100;

        /**
         * Reviews a report
         *
         * @param string $uuid The UUID of the report
         * @return DecisionRecord|null The decision, null if the report doesn't need a review (eg; it's closed, assigned
         *                             to a person, about a bot, or unchanged since its last decision)
         * @throws SystemOneException If the request to the System One endpoint failed
         * @throws BudgetExceededException If the daily token budget is used up
         * @throws ReviewException If the plugin operator is unavailable
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function review(string $uuid): ?DecisionRecord
        {
            $report = ReportManager::getReport($uuid);
            if($report === null || !$report->isOpened() || !self::isReviewable($report))
            {
                return null;
            }

            $entity = $report->getReportingEntity() !== null ? EntitiesManager::getEntityByUuid($report->getReportingEntity()) : null;
            if($entity !== null && ContextBuilder::isBot($entity))
            {
                Logger::log()->debug(sprintf('The report %s is about a bot, it is left to the operators', $uuid));
                return null;
            }

            $evidence = EvidenceManager::getEvidenceByReport($uuid, self::MAX_EVIDENCE, includeConfidential: true);
            if(!self::hasContent($evidence))
            {
                return null;
            }

            $fingerprint = self::fingerprint($report, $evidence);
            $latest = DecisionManager::getLatestDecision(SubjectType::REPORT, $uuid);
            if($latest !== null && $latest->getInputHash() === $fingerprint)
            {
                return null;
            }

            foreach($evidence as $record)
            {
                ImageReviewer::reviewEvidence($record->getUuid());
            }

            // What doesn't depend on the limits of the state is read once
            $sender = $entity !== null ? ContextBuilder::entityProfile($entity, new Limits()) + ['history' => ContextBuilder::history($entity, $uuid)] : null;
            $evaluation = Evaluator::evaluate(function(Limits $limits, ImageCollector $images) use ($report, $sender, $evidence): array
            {
                $messages = ContextBuilder::messages($evidence, $report->getReportingEntity(), $limits, $images);
                return ContextBuilder::compact([
                    'report' => ContextBuilder::report($report, $limits),
                    'sender' => $sender,
                    'messages' => $messages['items'],
                    'messages_left_out' => $messages['left_out'] ?: null,
                ]);
            }, ClassificationQuestions::build('messages', Configuration::getConcerns()));

            $verdict = ClassificationJudge::judge($evaluation->getResponse(), ClassificationJudge::REPORT_THRESHOLD);
            $outcome = DecisionOutcome::UNCERTAIN;
            $reason = $verdict->getReason();
            /** @var ClassificationFlag|null $flag */
            $flag = $verdict->getDetail('flag');

            if($verdict->isConfident() && $flag !== null)
            {
                if(Configuration::isDryRun())
                {
                    $outcome = DecisionOutcome::PREVIEWED;
                }
                elseif(self::close($uuid, $flag))
                {
                    $outcome = DecisionOutcome::APPLIED;
                    $reason = sprintf('closed as %s', $flag->value);
                    if($flag !== ClassificationFlag::NORMAL && $entity !== null && Configuration::getBlacklistConfiguration()->isEnabled())
                    {
                        QueueManager::enqueue(SubjectType::ENTITY, $entity->getUuid(), ReviewConfiguration::SETTLE_DELAY);
                    }
                }
                else
                {
                    $reason = 'the report changed before it could be closed';
                }
            }

            $decision = DecisionManager::record(
                subjectType: SubjectType::REPORT,
                subject: $uuid,
                entity: $entity?->getUuid(),
                outcome: $outcome,
                verdict: $verdict->getVerdict(),
                probability: $verdict->getProbability(),
                reason: $reason,
                inputHash: $fingerprint,
                model: $evaluation->getResponse()->getModel(),
                answers: $evaluation->getResponse()->getAnswers(),
                inputTokens: $evaluation->getResponse()->getInputTokens()
            );

            Logger::log()->info(sprintf('Report %s: %s with a probability of %.2f, %s (%s)', $uuid, $verdict->getVerdict(), $verdict->getProbability(), strtolower($outcome->value), $reason));
            return DecisionManager::getDecision($decision);
        }

        /**
         * Checks if the plugin may review a report: an unassigned report, or one assigned to itself or to a bot, unless
         * it's configured to take over reports assigned to people too
         *
         * @param ReportRecord $report The report
         * @return bool True if the report may be reviewed
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function isReviewable(ReportRecord $report): bool
        {
            $assigned = $report->getAssignedOperator();
            if($assigned === null || PluginOperator::isPluginOperator($assigned) || Configuration::getReviewConfiguration()->isIncludeAssigned())
            {
                return true;
            }

            $operator = OperatorManager::getOperator($assigned);
            return $operator === null || $operator->isBot() || $operator->isDisabled();
        }

        /**
         * Checks if the evidence has anything to judge: text or attachments
         *
         * @param EvidenceRecord[] $evidence The evidence records
         * @return bool True if there is something to judge
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function hasContent(array $evidence): bool
        {
            foreach($evidence as $record)
            {
                if($record->getTextContent() !== null && trim($record->getTextContent()) !== '')
                {
                    return true;
                }

                if(count(FileAttachmentManager::getRecordsByEvidence($record->getUuid())) > 0)
                {
                    return true;
                }
            }

            return false;
        }

        /**
         * Returns the fingerprint of what a report is judged on, it's only reviewed again once it changes
         *
         * @param ReportRecord $report The report
         * @param EvidenceRecord[] $evidence The evidence of the report
         * @return string The SHA-256 fingerprint
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function fingerprint(ReportRecord $report, array $evidence): string
        {
            $parts = [$report->getUuid(), $report->getMessage(), $report->getIncidentType()->value];
            foreach($evidence as $record)
            {
                $parts[] = [$record->getUuid(), $record->getUpdated() ?? $record->getCreated(), count(FileAttachmentManager::getRecordsByEvidence($record->getUuid()))];
            }

            return hash('sha256', (string)json_encode($parts));
        }

        /**
         * Closes a report with a classification, after checking under a lock that it's still opened and reviewable. The
         * plugin operator takes the report over to close it, OFD only lets the assigned operator close it
         *
         * @param string $uuid The UUID of the report
         * @param ClassificationFlag $flag The classification
         * @return bool True if the report was closed, false if it changed in the meantime
         * @throws ReviewException If the plugin operator is unavailable or the report is being reviewed elsewhere
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If FederationLib rejects the closure
         */
        private static function close(string $uuid, ClassificationFlag $flag): bool
        {
            $lock = DatabaseLock::getSubjectLock(SubjectType::REPORT->value, $uuid);
            if(!DatabaseLock::acquire($lock, 10))
            {
                throw new ReviewException(sprintf('The report %s is being reviewed elsewhere', $uuid));
            }

            try
            {
                $operator = PluginOperator::get();
                $report = ReportManager::getReport($uuid);
                if($report === null || !$report->isOpened() || !self::isReviewable($report))
                {
                    return false;
                }

                if($report->getAssignedOperator() !== $operator->getUuid())
                {
                    // Recorded like the Assign Operator to Report method records it, the plugin operator is enabled and
                    // holds management permissions as OFD requires of an assigned operator
                    ReportManager::assignOperator($uuid, $operator->getUuid());
                    AuditLogManager::createEntry(AuditLogType::REPORT_OPERATOR_ASSIGNED,
                        message: sprintf('Report %s assigned to operator %s', $uuid, $operator->getName()),
                        operatorUuid: $operator->getUuid(),
                        reportUuid: $uuid
                    );
                }

                ReportManager::closeReport($uuid, $operator, $flag);
                return true;
            }
            finally
            {
                DatabaseLock::releaseQuietly($lock);
            }
        }
    }
