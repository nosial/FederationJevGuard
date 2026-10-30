<?php

    namespace FederationJevGuard\Classes;

    use FederationJevGuard\Classes\Configuration\ReviewConfiguration;
    use FederationJevGuard\Classes\Managers\QueueManager;
    use FederationJevGuard\Classes\Reviewers\EntityReviewer;
    use FederationJevGuard\Classes\Reviewers\EvidenceReviewer;
    use FederationJevGuard\Classes\Reviewers\ImageReviewer;
    use FederationJevGuard\Classes\Reviewers\ReportReviewer;
    use FederationJevGuard\Enums\DecisionOutcome;
    use FederationJevGuard\Enums\SubjectType;
    use FederationJevGuard\Exceptions\BudgetExceededException;
    use FederationJevGuard\Exceptions\ReviewException;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\Objects\DecisionRecord;
    use FederationJevGuard\Objects\QueueRecord;
    use FederationLib\Exceptions\DatabaseOperationException;
    use InvalidArgumentException;

    class QueueWorker
    {
        private const int MAX_BACKOFF = 21600;

        /**
         * Reviews the due subjects
         *
         * @param int $maxItems The most subjects to review
         * @param int $seconds The seconds to review for, a review that started finishes
         * @return array{reviewed: int, decided: int, applied: int, failed: int, busy: bool} What was done
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function drain(int $maxItems, int $seconds): array
        {
            $result = ['reviewed' => 0, 'decided' => 0, 'applied' => 0, 'failed' => 0, 'busy' => false];
            if(!DatabaseLock::acquire(DatabaseLock::QUEUE))
            {
                $result['busy'] = true;
                return $result;
            }

            $started = time();
            try
            {
                while($result['reviewed'] < $maxItems && time() - $started < $seconds)
                {
                    $due = QueueManager::getDue(min(25, $maxItems - $result['reviewed']));
                    if(count($due) === 0)
                    {
                        break;
                    }

                    foreach($due as $item)
                    {
                        if($result['reviewed'] >= $maxItems || time() - $started >= $seconds)
                        {
                            break 2;
                        }

                        $result['reviewed']++;
                        try
                        {
                            $decision = self::process($item);
                            QueueManager::remove($item->getSubjectType(), $item->getSubject());
                            if($decision !== null)
                            {
                                $result['decided']++;
                                $result['applied'] += $decision->getOutcome() === DecisionOutcome::APPLIED ? 1 : 0;
                            }
                        }
                        catch(BudgetExceededException $e)
                        {
                            Logger::log()->info($e->getMessage() . ', the queue is reviewed again tomorrow (UTC)');
                            break 2;
                        }
                        catch(SystemOneException $e)
                        {
                            $result['failed']++;
                            self::handleFailure($item, $e);
                        }
                        catch(ReviewException $e)
                        {
                            $result['failed']++;
                            Logger::log()->warning(sprintf('The review of %s %s is tried again later: %s', $item->getSubjectType()->value, $item->getSubject(), $e->getMessage()));
                            QueueManager::postpone($item->getSubjectType(), $item->getSubject(), 300);
                        }
                        catch(InvalidArgumentException $e)
                        {
                            $result['failed']++;
                            Logger::log()->warning(sprintf('The review of %s %s is dropped: %s', $item->getSubjectType()->value, $item->getSubject(), $e->getMessage()));
                            QueueManager::remove($item->getSubjectType(), $item->getSubject());
                        }
                    }
                }
            }
            finally
            {
                DatabaseLock::releaseQuietly(DatabaseLock::QUEUE);
                ImageReviewer::clearCache();
                ContextBuilder::clearCache();
            }

            return $result;
        }

        /**
         * Reviews a subject with the reviewer of its type, the reviews that are turned off are skipped
         *
         * @param QueueRecord $item The subject
         * @return DecisionRecord|null The decision, null if no decision was needed
         * @throws SystemOneException If the request to the System One endpoint failed
         * @throws BudgetExceededException If the daily token budget is used up
         * @throws ReviewException If the review could not be made
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function process(QueueRecord $item): ?DecisionRecord
        {
            return match($item->getSubjectType())
            {
                SubjectType::REPORT => Configuration::getReviewConfiguration()->isReportsEnabled() ? ReportReviewer::review($item->getSubject()) : null,
                SubjectType::EVIDENCE => Configuration::getReviewConfiguration()->isEvidenceEnabled() ? EvidenceReviewer::review($item->getSubject()) : null,
                SubjectType::ENTITY => Configuration::getBlacklistConfiguration()->isEnabled() ? EntityReviewer::review($item->getSubject()) : null,
                SubjectType::ATTACHMENT => ImageReviewer::review($item->getSubject()),
            };
        }

        /**
         * Handles a review that failed because of the endpoint: a transient failure is tried again later, waiting
         * longer each time, rejected credentials wait an hour, and a request the endpoint rejects for good is dropped
         *
         * @param QueueRecord $item The subject
         * @param SystemOneException $e The failure
         * @return void
         * @throws DatabaseOperationException If there is an error with the database
         */
        private static function handleFailure(QueueRecord $item, SystemOneException $e): void
        {
            $subject = sprintf('%s %s', $item->getSubjectType()->value, $item->getSubject());
            if($e->getStatus() === 401 || $e->getStatus() === 403)
            {
                Logger::log()->error(sprintf('The System One endpoint rejected the credentials, check FEDERATION_JEVGUARD_SYSTEMONE_API_KEY. The review of %s is tried again in an hour: %s', $subject, $e->getMessage()));
                QueueManager::postpone($item->getSubjectType(), $item->getSubject(), 3600);
                return;
            }

            if(!$e->isTransient())
            {
                Logger::log()->error(sprintf('The System One endpoint rejected the review of %s, it is dropped: %s', $subject, $e->getMessage()), $e);
                QueueManager::remove($item->getSubjectType(), $item->getSubject());
                return;
            }

            if($item->getAttempts() + 1 >= ReviewConfiguration::MAX_ATTEMPTS)
            {
                Logger::log()->warning(sprintf('The review of %s failed %d times, it is dropped until the subject changes: %s', $subject, $item->getAttempts() + 1, $e->getMessage()));
                QueueManager::remove($item->getSubjectType(), $item->getSubject());
                return;
            }

            $delay = min(self::MAX_BACKOFF, 60 * 2 ** $item->getAttempts());
            Logger::log()->warning(sprintf('The review of %s is tried again in %d seconds: %s', $subject, $delay, $e->getMessage()));
            QueueManager::postpone($item->getSubjectType(), $item->getSubject(), $delay);
        }
    }
