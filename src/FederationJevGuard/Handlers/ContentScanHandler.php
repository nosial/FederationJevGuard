<?php

    namespace FederationJevGuard\Handlers;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\ContextBuilder;
    use FederationJevGuard\Classes\Evaluator;
    use FederationJevGuard\Classes\ImageCollector;
    use FederationJevGuard\Classes\Judges\ClassificationJudge;
    use FederationJevGuard\Classes\Logger;
    use FederationJevGuard\Classes\Questions\ClassificationQuestions;
    use FederationJevGuard\Classes\Reviewers\ImageReviewer;
    use FederationJevGuard\Classes\TextSignals;
    use FederationJevGuard\Classes\UntrustedContent;
    use FederationJevGuard\Exceptions\ReviewException;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\FederationJevGuard;
    use FederationJevGuard\Objects\Limits;
    use FederationJevGuard\Objects\PreparedImage;
    use FederationLib\Enums\ContentType;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\ContentScanEventHandlerInterface;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\Plugin\ScanFile;
    use FederationLib\Objects\ScannedContent\ContentClassification;

    class ContentScanHandler implements ContentScanEventHandlerInterface
    {
        /**
         * The timeout of a request made during a scan, in seconds
         */
        public const int TIMEOUT = 5;

        /**
         * The most messages of a scan that are classified
         */
        public const int MAX_MESSAGES = 5;

        /**
         * The probability from which a signal of a scam, phishing or malware is a detection of its concern
         */
        public const float DETECTION = 0.8;

        /**
         * The most images of a scan that are prepared, each is decoded in the media sandbox while the client waits
         */
        public const int MAX_IMAGES = 2;

        /**
         * The content types that are messages, hostnames and URLs are left to other plugins (eg; FederationAdGuard)
         */
        private const array MESSAGE_TYPES = [ContentType::TEXT, ContentType::EMAIL, ContentType::SMS, ContentType::WEB_PAGE];

        /**
         * @inheritDoc
         */
        public static function handleContentScan(ContentScan $contentScan): void
        {
            if(!Configuration::isScanningEnabled() || $contentScan->getAuthenticatedOperator() === null
                || !$contentScan->isConcernEffective(IncidentType::SPAM) || !FederationJevGuard::isOperational())
            {
                return;
            }

            $messages = [];
            foreach($contentScan->getEvidence() as $index => $evidence)
            {
                if(in_array($evidence->getContentType(), self::MESSAGE_TYPES, true) && $evidence->getTextContent() !== null && trim($evidence->getTextContent()) !== '')
                {
                    $messages[$index] = $evidence;
                }

                if(count($messages) >= self::MAX_MESSAGES)
                {
                    break;
                }
            }

            if(count($messages) === 0)
            {
                return;
            }

            $questions = [];
            $position = 0;
            foreach(array_keys($messages) as $index)
            {
                $questions += ClassificationQuestions::build(sprintf('messages[%d]', $position++), [], self::prefix($index));
            }

            // The files of a message are only context of its text, never classified on their own; images are prepared once,
            // not for every attempt to fit the state
            $attachments = [];
            $prepared = 0;
            foreach(array_keys($messages) as $index)
            {
                foreach(array_slice($contentScan->getFiles($index), 0, ContextBuilder::MAX_ATTACHMENTS) as $file)
                {
                    $image = null;
                    if($prepared < self::MAX_IMAGES && str_starts_with($file->getMimeType(), 'image/'))
                    {
                        $prepared++;
                        $image = ImageReviewer::loadScanFile($file);
                    }

                    $attachments[$index][] = ['file' => $file, 'image' => $image];
                }
            }

            $author = $contentScan->getAuthorEntity();
            TextSignals::clearCache();
            try
            {
                $evaluation = Evaluator::evaluate(function(Limits $limits, ImageCollector $images) use ($messages, $author, $attachments): array
                {
                    return ContextBuilder::compact([
                        'sender' => $author !== null ? ContextBuilder::compact(ContextBuilder::entitySummary($author->getEntity(), $limits) + [
                            'active_blacklists' => count($author->getActiveBlacklists()) ?: null,
                        ]) : null,
                        'messages' => array_values(array_map(fn($evidence, $index) => ContextBuilder::compact([
                            'content_type' => $evidence->getContentType()->value,
                            'text' => ContextBuilder::cut($evidence->getTextContent(), $limits->getTextLength()),
                            'text_signals' => TextSignals::analyze($evidence->getTextContent(), $limits->getTextLength()),
                            'mentions' => TextSignals::mentions($evidence->getTextContent(), $author?->getEntity()->getUuid()),
                            'context_claimed' => ContextBuilder::metadata($evidence->getContext(), $limits),
                            'metadata' => ContextBuilder::metadata($evidence->getMetadata(), $limits),
                            'attachments' => self::attachments($attachments[$index] ?? [], $images),
                        ]), $messages, array_keys($messages))),
                    ]);
                }, $questions, self::TIMEOUT, 0);
            }
            catch(SystemOneException|ReviewException|DatabaseOperationException $e)
            {
                Logger::log()->warning('The scan continues without a classification of FederationJevGuard: ' . $e->getMessage());
                return;
            }

            foreach($messages as $index => $evidence)
            {
                $prefix = self::prefix($index);
                $verdict = ClassificationJudge::judge($evaluation->getResponse(), ClassificationJudge::SCAN_THRESHOLD, $prefix);
                if(!$verdict->isConfident())
                {
                    continue;
                }

                $contentScan->addClassification(new ContentClassification($verdict->getDetail('flag'), $verdict->getProbability(), null), $index);

                // The context of a message (eg; the subject of an email) is the client's assertion
                $verified = $evidence->getContext() === null;
                foreach([IncidentType::SCAM->value => ClassificationQuestions::SCAM, IncidentType::PHISHING->value => ClassificationQuestions::SCAM,
                            IncidentType::MALWARE->value => ClassificationQuestions::MALWARE] as $concern => $question)
                {
                    $probability = $evaluation->getResponse()->noul($prefix . $question)->getNoul();
                    if($probability >= self::DETECTION && $contentScan->isConcernEffective(IncidentType::from($concern)))
                    {
                        $contentScan->addDetection(IncidentType::from($concern), $index, 'jevguard', null, $probability, $verified);
                    }
                }
            }
        }

        /**
         * Returns the files of a message as attachments: their type and size, and, while the state has room, the image
         * with what the model found in it before
         *
         * @param array<int, array{file: ScanFile, image: PreparedImage|null}> $attachments The files of the message
         * @param ImageCollector $images The images of the state
         * @return array The attachments
         * @throws DatabaseOperationException If there is an error with the database
         */
        private static function attachments(array $attachments, ImageCollector $images): array
        {
            $items = [];
            foreach($attachments as ['file' => $file, 'image' => $image])
            {
                $item = [
                    'name_claimed' => ContextBuilder::cut(UntrustedContent::sanitizeText($file->getFileName()), 120),
                    'type' => $file->getMimeType(),
                    'bytes' => $file->getSize(),
                ];

                if($image !== null)
                {
                    $findings = ImageReviewer::getFindings($image->getSha256());
                    $item['image'] = $images->add($image);
                    $item['image_findings'] = $findings !== null ? array_map(fn(float $probability) => round($probability, 2), $findings->getFindings()) : null;
                }

                $items[] = ContextBuilder::compact($item);
            }

            return $items;
        }

        /**
         * Returns the prefix of the questions about a message
         *
         * @param int $index The index of the message in the scan
         * @return string The prefix
         */
        private static function prefix(int $index): string
        {
            return sprintf('m%d_', $index);
        }
    }
