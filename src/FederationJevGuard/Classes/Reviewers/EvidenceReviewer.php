<?php

    namespace FederationJevGuard\Classes\Reviewers;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\Configuration\ReviewConfiguration;
    use FederationJevGuard\Classes\ContextBuilder;
    use FederationJevGuard\Classes\Evaluator;
    use FederationJevGuard\Classes\ImageCollector;
    use FederationJevGuard\Classes\Judges\ClassificationJudge;
    use FederationJevGuard\Classes\Logger;
    use FederationJevGuard\Classes\Managers\DecisionManager;
    use FederationJevGuard\Classes\Managers\QueueManager;
    use FederationJevGuard\Classes\Questions\ClassificationQuestions;
    use FederationJevGuard\Enums\DecisionOutcome;
    use FederationJevGuard\Enums\SubjectType;
    use FederationJevGuard\Exceptions\BudgetExceededException;
    use FederationJevGuard\Exceptions\ReviewException;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\Objects\DecisionRecord;
    use FederationJevGuard\Objects\Limits;
    use FederationJevGuard\Classes\PluginOperator;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Exceptions\DatabaseOperationException;
    use InvalidArgumentException;

    class EvidenceReviewer
    {
        /**
         * Reviews an evidence record
         *
         * @param string $uuid The UUID of the evidence record
         * @return DecisionRecord|null The decision, null if the evidence doesn't need a review (eg; it's classified,
         *                             belongs to a report, is about a bot or didn't change since its last decision)
         * @throws SystemOneException If the request to the System One endpoint failed
         * @throws BudgetExceededException If the daily token budget is used up
         * @throws ReviewException If the plugin operator is unavailable
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function review(string $uuid): ?DecisionRecord
        {
            $evidence = EvidenceManager::getEvidence($uuid);
            if($evidence === null || $evidence->getClassificationFlag() !== null || $evidence->getReport() !== null || !ReportReviewer::hasContent([$evidence]))
            {
                return null;
            }

            $entity = EntitiesManager::getEntityByUuid($evidence->getEntityUuid());
            if($entity === null || ContextBuilder::isBot($entity))
            {
                return null;
            }

            $fingerprint = hash('sha256', (string)json_encode([$uuid, $evidence->getUpdated() ?? $evidence->getCreated(), count(FileAttachmentManager::getRecordsByEvidence($uuid))]));
            $latest = DecisionManager::getLatestDecision(SubjectType::EVIDENCE, $uuid);
            if($latest !== null && $latest->getInputHash() === $fingerprint)
            {
                return null;
            }

            ImageReviewer::reviewEvidence($uuid);
            $sender = ContextBuilder::entityProfile($entity, new Limits()) + ['history' => ContextBuilder::history($entity)];
            $evaluation = Evaluator::evaluate(function(Limits $limits, ImageCollector $images) use ($evidence, $sender): array
            {
                return [
                    'sender' => $sender,
                    'messages' => [ContextBuilder::evidence($evidence, $evidence->getEntityUuid(), $limits, $images)],
                ];
            }, ClassificationQuestions::build('messages', Configuration::getConcerns()));

            $verdict = ClassificationJudge::judge($evaluation->getResponse(), ClassificationJudge::EVIDENCE_THRESHOLD);
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
                elseif(($operator = PluginOperator::get()) && EvidenceManager::updateClassificationFlag($uuid, $flag))
                {
                    // Recorded like the Classify Evidence method records it
                    AuditLogManager::createEntry(AuditLogType::EVIDENCE_UPDATED, sprintf('Evidence %s classified as %s by %s', $uuid, $flag->value, $operator->getName()),
                        $operator->getUuid(), $evidence->getEntityUuid(), null, $uuid);

                    $outcome = DecisionOutcome::APPLIED;
                    $reason = sprintf('classified as %s', $flag->value);
                    if($flag !== ClassificationFlag::NORMAL && Configuration::getBlacklistConfiguration()->isEnabled())
                    {
                        QueueManager::enqueue(SubjectType::ENTITY, $entity->getUuid(), ReviewConfiguration::SETTLE_DELAY);
                    }
                }
                else
                {
                    $reason = 'the evidence was classified in the meantime';
                }
            }

            $decision = DecisionManager::record(
                subjectType: SubjectType::EVIDENCE,
                subject: $uuid,
                entity: $entity->getUuid(),
                outcome: $outcome,
                verdict: $verdict->getVerdict(),
                probability: $verdict->getProbability(),
                reason: $reason,
                inputHash: $fingerprint,
                model: $evaluation->getResponse()->getModel(),
                answers: $evaluation->getResponse()->getAnswers(),
                inputTokens: $evaluation->getResponse()->getInputTokens()
            );

            Logger::log()->info(sprintf('Evidence %s: %s with a probability of %.2f, %s (%s)', $uuid, $verdict->getVerdict(), $verdict->getProbability(), strtolower($outcome->value), $reason));
            return DecisionManager::getDecision($decision);
        }
    }
