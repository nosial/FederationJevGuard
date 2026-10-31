<?php

    namespace FederationJevGuard\Classes;

    use FederationJevGuard\Classes\Reviewers\ImageReviewer;
    use FederationJevGuard\Objects\Limits;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\FileAttachmentRecord;
    use FederationLib\Objects\ReportRecord;
    use InvalidArgumentException;

    class ContextBuilder
    {
        public const int MAX_METADATA_LENGTH = 300;
        public const int MAX_RELATED = 10;
        public const int MAX_BLACKLISTS = 20;
        public const int HISTORY_REPORTS = 10;
        public const int MAX_ATTACHMENTS = 10;
        private const array TEXT_MIME_TYPES = ['application/json', 'application/xml', 'application/x-yaml', 'application/yaml', 'message/rfc822'];

        /** @var array<string, string> How each operator is referred to, by UUID */
        private static array $operators = [];

        /**
         * Returns a short profile of an entity, for the states of content scans and images
         *
         * @param EntityRecord $entity The entity
         * @param Limits $limits The limits of the state
         * @return array The profile
         */
        public static function entitySummary(EntityRecord $entity, Limits $limits): array
        {
            return self::compact([
                'address' => self::address($entity),
                'reputation' => $entity->getReputation(),
                'whitelisted' => $entity->isWhitelisted(),
                'bot' => self::isBot($entity) ? true : null,
                'first_seen_days_ago' => self::daysAgo($entity->getCreated()),
                'metadata' => self::metadata($entity->getMetadata(), $limits),
            ]);
        }

        /**
         * Returns the profile of an entity with its relationships and blacklist history
         *
         * @param EntityRecord $entity The entity
         * @param Limits $limits The limits of the state
         * @return array The profile
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function entityProfile(EntityRecord $entity, Limits $limits): array
        {
            $relationship = null;
            if($entity->getRelationshipEntity() !== null)
            {
                $target = EntitiesManager::getEntityByUuid($entity->getRelationshipEntity());
                $relationship = self::compact([
                    'type' => $entity->getRelationshipType()?->value,
                    'entity' => $target !== null ? self::address($target) : null,
                    'entity_reputation' => $target?->getReputation(),
                ]);
            }

            $related = array_slice(EntitiesManager::getEntitiesByRelationshipEntity($entity->getUuid()), 0, self::MAX_RELATED);
            $blacklists = BlacklistManager::getEntriesByEntity($entity->getUuid(), self::MAX_BLACKLISTS, 1, true);

            return self::compact(self::entitySummary($entity, $limits) + [
                'relationship' => $relationship,
                'related_entities' => array_map(fn(EntityRecord $record) => self::address($record), $related),
                'blacklists' => array_map(fn(BlacklistRecord $record) => self::blacklist($record), $blacklists),
                'active_blacklists' => count(array_filter($blacklists, fn(BlacklistRecord $record) => self::isActive($record))),
            ]);
        }

        /**
         * Returns a blacklist record of an entity's history
         *
         * @param BlacklistRecord $record The blacklist record
         * @return array The blacklist record
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function blacklist(BlacklistRecord $record): array
        {
            return self::compact([
                'incident_type' => $record->getType()->value,
                'created' => self::date($record->getCreated()),
                'expires' => $record->getExpires() !== null ? self::date($record->getExpires()) : 'never',
                'active' => self::isActive($record),
                'by' => self::operator($record->getOperatorUuid()),
                'lifted_by' => $record->isLifted() ? (self::operator($record->getLiftedBy()) ?? 'an operator') : null,
            ]);
        }

        /**
         * Returns a report without its evidence
         *
         * @param ReportRecord $report The report
         * @param Limits $limits The limits of the state
         * @return array The report
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function report(ReportRecord $report, Limits $limits): array
        {
            return self::compact([
                'incident_type_claimed' => $report->getIncidentType()->value,
                'reporter_message_claim' => self::cut($report->getMessage(), $limits->getTextLength()),
                'reported_by' => self::operator($report->getSubmittingOperator()),
                'automated' => $report->isAutomated() ? true : null,
                'created' => self::date($report->getCreated()),
                'days_ago' => self::daysAgo($report->getCreated()),
            ]);
        }

        /**
         * Returns the messages of evidence records in the order they were sent, identical texts counted once. Items
         * beyond the limits are left out and counted
         *
         * @param EvidenceRecord[] $evidence The evidence records
         * @param string|null $about The UUID of the entity the evidence is usually about, others are named
         * @param Limits $limits The limits of the state
         * @param ImageCollector $images The images of the state
         * @return array{items: array, left_out: int} The messages
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function messages(array $evidence, ?string $about, Limits $limits, ImageCollector $images): array
        {
            usort($evidence, fn(EvidenceRecord $a, EvidenceRecord $b) => $a->getCreated() <=> $b->getCreated());

            $items = [];
            $positions = [];
            $leftOut = 0;
            foreach($evidence as $record)
            {
                $key = $record->getTextContent() !== null ? hash('sha256', $record->getEntityUuid() . "\0" . TextSignals::fingerprint($record->getTextContent())) : null;
                if($key !== null && isset($positions[$key]))
                {
                    $items[$positions[$key]]['times_sent'] = ($items[$positions[$key]]['times_sent'] ?? 1) + 1;
                    continue;
                }

                if(count($items) >= $limits->getMaxItems())
                {
                    $leftOut++;
                    continue;
                }

                if($key !== null)
                {
                    $positions[$key] = count($items);
                }

                $items[] = self::evidence($record, $about, $limits, $images);
            }

            return ['items' => $items, 'left_out' => $leftOut];
        }

        /**
         * Returns an evidence record as a message
         *
         * @param EvidenceRecord $evidence The evidence record
         * @param string|null $about The UUID of the entity the evidence is usually about, another one is named
         * @param Limits $limits The limits of the state
         * @param ImageCollector $images The images of the state
         * @return array The message
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function evidence(EvidenceRecord $evidence, ?string $about, Limits $limits, ImageCollector $images): array
        {
            $entity = null;
            if($evidence->getEntityUuid() !== $about)
            {
                $record = EntitiesManager::getEntityByUuid($evidence->getEntityUuid());
                $entity = $record !== null ? self::address($record) : null;
            }

            return self::compact([
                'about' => $entity,
                'content_type' => $evidence->getContentType()->value,
                'text' => self::cut($evidence->getTextContent(), $limits->getTextLength()),
                'text_signals' => TextSignals::analyze($evidence->getTextContent(), $limits->getTextLength()),
                'mentions' => TextSignals::mentions($evidence->getTextContent(), $evidence->getEntityUuid()),
                'context_claimed' => self::metadata($evidence->getContext(), $limits),
                'sent' => self::date($evidence->getCreated()),
                'note' => self::cut($evidence->getNote(), min($limits->getTextLength(), 1000)),
                'tag' => $evidence->getTag(),
                'earlier_classification' => $evidence->getClassificationFlag()?->value,
                'submitted_by' => self::operator($evidence->getOperatorUuid()),
                'metadata' => self::metadata($evidence->getMetadata(), $limits),
                'attachments' => self::attachments($evidence->getUuid(), $limits, $images),
            ]);
        }

        /**
         * Returns the attachments of an evidence record: text attachments with their text, images with what the model
         * found in them and, while the state has room, the image itself
         *
         * @param string $evidenceUuid The UUID of the evidence record
         * @param Limits $limits The limits of the state
         * @param ImageCollector $images The images of the state
         * @return array The attachments
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function attachments(string $evidenceUuid, Limits $limits, ImageCollector $images): array
        {
            $attachments = [];
            foreach(array_slice(FileAttachmentManager::getRecordsByEvidence($evidenceUuid), 0, self::MAX_ATTACHMENTS) as $attachment)
            {
                $item = [
                    'name' => self::cut($attachment->getFileName(), 120),
                    'type' => $attachment->getFileMime(),
                    'bytes' => $attachment->getFileSize(),
                ];

                if(self::isText($attachment->getFileMime()))
                {
                    $item['text'] = self::readText($attachment, $limits->getTextLength());
                }
                else
                {
                    $image = ImageReviewer::load($attachment);
                    if($image !== null)
                    {
                        $findings = ImageReviewer::getFindings($image->getSha256());
                        $item['image'] = $images->add($image);
                        $item['image_findings'] = $findings !== null ? array_map(fn(float $probability) => round($probability, 2), $findings->getFindings()) : null;
                    }
                }

                $attachments[] = self::compact($item);
            }

            return $attachments;
        }

        /**
         * Returns the history of an entity's earlier reports: how many there are, how they were classified, and the
         * most recent ones
         *
         * @param EntityRecord $entity The entity
         * @param string|null $excludeReport The UUID of a report left out, eg; the report being reviewed
         * @return array The history
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function history(EntityRecord $entity, ?string $excludeReport=null): array
        {
            $reports = array_filter(ReportManager::getReportsByReportingEntity($entity->getUuid(), self::HISTORY_REPORTS + 1),
                fn(ReportRecord $report) => $report->getUuid() !== $excludeReport);
            $reports = array_slice($reports, 0, self::HISTORY_REPORTS);

            $counts = ['opened' => 0, ClassificationFlag::NORMAL->value => 0, ClassificationFlag::SUSPICIOUS->value => 0, ClassificationFlag::MALICIOUS->value => 0, 'unclassified' => 0];
            $recent = [];
            foreach($reports as $report)
            {
                $classification = self::getReportClassification(EvidenceManager::getEvidenceByReport($report->getUuid(), 25, includeConfidential: true));
                if($report->isOpened())
                {
                    $counts['opened']++;
                }
                else
                {
                    $counts[$classification?->value ?? 'unclassified']++;
                }

                $recent[] = self::compact([
                    'incident_type_claimed' => $report->getIncidentType()->value,
                    'classification' => $report->isOpened() ? 'still opened' : ($classification?->value ?? 'closed unclassified'),
                    'days_ago' => self::daysAgo($report->getCreated()),
                ]);
            }

            return self::compact([
                'earlier_reports' => count($reports),
                'earlier_reports_more' => count($reports) >= self::HISTORY_REPORTS ? true : null,
                'by_outcome' => array_filter($counts),
                'recent' => $recent,
            ]);
        }

        /**
         * Returns the classification of a report, the worst classification of its evidence
         *
         * @param EvidenceRecord[] $evidence The evidence of the report
         * @return ClassificationFlag|null The classification, null if no evidence is classified
         */
        public static function getReportClassification(array $evidence): ?ClassificationFlag
        {
            $worst = null;
            foreach($evidence as $record)
            {
                $flag = $record->getClassificationFlag();
                if($flag === ClassificationFlag::MALICIOUS)
                {
                    return $flag;
                }

                if($flag === ClassificationFlag::SUSPICIOUS || ($flag === ClassificationFlag::NORMAL && $worst === null))
                {
                    $worst = $flag;
                }
            }

            return $worst;
        }

        /**
         * Checks if an entity is flagged as a bot in its metadata (is_bot, eg; a Telegram bot account). Bots are never
         * judged nor blacklisted by the plugin
         *
         * @param EntityRecord $entity The entity
         * @return bool True for a bot
         */
        public static function isBot(EntityRecord $entity): bool
        {
            $value = $entity->getMetadata()['is_bot'] ?? null;
            if(is_string($value))
            {
                return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
            }

            return $value === true || $value === 1;
        }

        /**
         * Checks if a blacklist record is active: not lifted and not expired
         *
         * @param BlacklistRecord $record The blacklist record
         * @return bool True if active
         */
        public static function isActive(BlacklistRecord $record): bool
        {
            return !$record->isLifted() && ($record->getExpires() === null || $record->getExpires() > time());
        }

        /**
         * Returns how an entity is referred to: its address, except for a telephone entity whose number is personal
         * information that never leaves the server
         *
         * @param EntityRecord $entity The entity
         * @return string The address
         */
        public static function address(EntityRecord $entity): string
        {
            return $entity->isTelephone() ? 'a telephone number' : $entity->getAddress();
        }

        /**
         * Returns how an operator is referred to: this plugin, the server's scanner, a bot or an operator by name
         *
         * @param string|null $uuid The UUID of the operator
         * @return string|null The description, null without an operator
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function operator(?string $uuid): ?string
        {
            if($uuid === null)
            {
                return null;
            }

            if(!isset(self::$operators[$uuid]))
            {
                $operator = OperatorManager::getOperator($uuid);
                self::$operators[$uuid] = match(true)
                {
                    $operator === null => 'a deleted operator',
                    PluginOperator::isPluginOperator($uuid) => 'this reviewer (an earlier decision)',
                    OperatorManager::isSystemOperator($uuid) => "the server's automatic content scanner",
                    $operator->isBot() => sprintf('the bot %s', $operator->getName()),
                    default => sprintf('the operator %s', $operator->getName())
                };
            }

            return self::$operators[$uuid];
        }

        /**
         * Returns metadata with every value cut to the limits, nested keys are kept as they are
         *
         * @param array|null $metadata The metadata
         * @param Limits $limits The limits of the state
         * @return array|null The metadata, null if empty
         */
        public static function metadata(?array $metadata, Limits $limits): ?array
        {
            if(empty($metadata))
            {
                return null;
            }

            $length = min($limits->getTextLength(), self::MAX_METADATA_LENGTH);
            $result = [];
            foreach($metadata as $key => $value)
            {
                if($value === null || $value === '')
                {
                    continue;
                }

                $result[(string)$key] = is_string($value) ? self::cut($value, $length) : (is_scalar($value) ? $value : self::cut((string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $length));
            }

            return count($result) > 0 ? $result : null;
        }

        /**
         * Cleans a text (see UntrustedContent::sanitizeText()) and cuts it to a length, marking where it was cut
         *
         * @param string|null $text The text
         * @param int $length The longest text, in characters
         * @return string|null The text, null if it's empty
         */
        public static function cut(?string $text, int $length): ?string
        {
            if($text === null)
            {
                return null;
            }

            $text = trim(UntrustedContent::sanitizeText($text));
            if($text === '')
            {
                return null;
            }

            return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length)) . '…';
        }

        /**
         * Formats a timestamp as a date, dates are only compared in code
         *
         * @param int|null $timestamp The Unix timestamp
         * @return string|null The date, null without a timestamp
         */
        public static function date(?int $timestamp): ?string
        {
            return $timestamp !== null && $timestamp > 0 ? gmdate('Y-m-d H:i', $timestamp) . ' UTC' : null;
        }

        /**
         * Returns the whole days since a timestamp
         *
         * @param int|null $timestamp The Unix timestamp
         * @return int|null The days, null without a timestamp
         */
        public static function daysAgo(?int $timestamp): ?int
        {
            return $timestamp !== null && $timestamp > 0 ? max(0, intdiv(time() - $timestamp, 86400)) : null;
        }

        /**
         * Leaves out the empty values of an array, every character counts towards the model's context
         *
         * @param array $data The array
         * @return array The array without empty values
         */
        public static function compact(array $data): array
        {
            return array_filter($data, fn($value) => $value !== null && $value !== '' && $value !== []);
        }

        /**
         * Forgets the operators read in this process
         *
         * @return void
         */
        public static function clearCache(): void
        {
            self::$operators = [];
            TextSignals::clearCache();
        }

        /**
         * Checks if the content of an attachment is given to the model as text
         *
         * @param string $mime The MIME type of the attachment
         * @return bool True for text
         */
        private static function isText(string $mime): bool
        {
            $mime = strtolower(trim(explode(';', $mime)[0]));
            return str_starts_with($mime, 'text/') || in_array($mime, self::TEXT_MIME_TYPES, true) || str_ends_with($mime, '+json') || str_ends_with($mime, '+xml');
        }

        /**
         * Reads the beginning of a text attachment
         *
         * @param FileAttachmentRecord $attachment The attachment
         * @param int $length The longest text, in characters
         * @return string|null The text, null if it can't be read
         */
        private static function readText(FileAttachmentRecord $attachment, int $length): ?string
        {
            // Only the beginning is read, the text is cleaned like every other text
            $content = UntrustedContent::readAttachment($attachment, $length * 4, false);
            return $content !== null ? self::cut($content, $length) : null;
        }
    }
