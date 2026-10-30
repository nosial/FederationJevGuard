<?php

    namespace FederationJevGuard\Classes\Reviewers;

    use FederationJevGuard\Classes\Configuration;
    use FederationJevGuard\Classes\ContextBuilder;
    use FederationJevGuard\Classes\Evaluator;
    use FederationJevGuard\Classes\ImageCollector;
    use FederationJevGuard\Classes\ImagePreparer;
    use FederationJevGuard\Classes\Managers\DecisionManager;
    use FederationJevGuard\Classes\Managers\ImageManager;
    use FederationJevGuard\Classes\Questions\ImageQuestions;
    use FederationJevGuard\Classes\UntrustedContent;
    use FederationJevGuard\Enums\DecisionOutcome;
    use FederationJevGuard\Enums\SubjectType;
    use FederationJevGuard\Exceptions\BudgetExceededException;
    use FederationJevGuard\Exceptions\SystemOneException;
    use FederationJevGuard\Objects\DecisionRecord;
    use FederationJevGuard\Objects\ImageRecord;
    use FederationJevGuard\Objects\Limits;
    use FederationJevGuard\Objects\PreparedImage;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\FileAttachmentRecord;
    use FederationLib\Objects\Plugin\ScanFile;
    use InvalidArgumentException;

    class ImageReviewer
    {
        public const float FINDING = 0.7;

        /** @var array<string, PreparedImage|null> The images prepared in this process, by attachment */
        private static array $prepared = [];
        /** @var array<string, ImageRecord|null> The findings read in this process, by hash */
        private static array $findings = [];

        /**
         * Judges an image attachment, unless its image was judged before
         *
         * @param string $attachmentUuid The UUID of the attachment
         * @return DecisionRecord|null The decision, null if the attachment is not an image that can be judged, or its
         *                             image was judged before
         * @throws SystemOneException If the request to the System One endpoint failed
         * @throws BudgetExceededException If the daily token budget is used up
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function review(string $attachmentUuid): ?DecisionRecord
        {
            if(!Configuration::isImagesEnabled())
            {
                return null;
            }

            $attachment = FileAttachmentManager::getRecord($attachmentUuid);
            $image = $attachment !== null ? self::load($attachment) : null;
            if($image === null || self::getFindings($image->getSha256()) !== null)
            {
                return null;
            }

            $evidence = $attachment->getEvidenceUuid() !== null ? EvidenceManager::getEvidence($attachment->getEvidenceUuid()) : null;
            $sender = $evidence !== null ? EntitiesManager::getEntityByUuid($evidence->getEntityUuid()) : null;
            $evaluation = Evaluator::evaluate(function(Limits $limits, ImageCollector $images) use ($image, $evidence, $sender): array
            {
                return ContextBuilder::compact([
                    'image' => $images->add($image),
                    'message' => ContextBuilder::cut($evidence?->getTextContent(), $limits->getTextLength()),
                    'sender' => $sender !== null ? ContextBuilder::entitySummary($sender, $limits) : null,
                ]);
            }, ImageQuestions::build());

            if($evaluation->getImages() === 0)
            {
                // Only possible when images were turned off in the meantime
                return null;
            }

            $findings = [];
            foreach(array_keys(ImageQuestions::build()) as $name)
            {
                $findings[$name] = $evaluation->getResponse()->noul($name)->getNoul();
            }

            ImageManager::storeImage($image->getSha256(), $findings, $evaluation->getResponse()->getModel());
            self::$findings[$image->getSha256()] = new ImageRecord(['sha256' => $image->getSha256(), 'findings' => $findings, 'model' => $evaluation->getResponse()->getModel()]);

            arsort($findings);
            $top = (string)array_key_first($findings);
            $probability = $findings[$top];
            $uuid = DecisionManager::record(
                subjectType: SubjectType::ATTACHMENT,
                subject: $attachmentUuid,
                entity: $evidence?->getEntityUuid(),
                outcome: $probability >= self::FINDING ? DecisionOutcome::APPLIED : DecisionOutcome::DECLINED,
                verdict: $probability >= self::FINDING ? strtoupper($top) : 'NONE',
                probability: $probability,
                reason: $probability >= self::FINDING ? 'the image is recorded as a spam image' : 'nothing was found in the image',
                inputHash: $image->getSha256(),
                model: $evaluation->getResponse()->getModel(),
                answers: $evaluation->getResponse()->getAnswers(),
                inputTokens: $evaluation->getResponse()->getInputTokens()
            );

            return DecisionManager::getDecision($uuid);
        }

        /**
         * Judges the images of an evidence record that were not judged yet, so that its review is given their findings
         *
         * @param string $evidenceUuid The UUID of the evidence record
         * @return void
         * @throws SystemOneException If the request to the System One endpoint failed
         * @throws BudgetExceededException If the daily token budget is used up
         * @throws DatabaseOperationException If there is an error with the database
         * @throws InvalidArgumentException If a record is invalid
         */
        public static function reviewEvidence(string $evidenceUuid): void
        {
            if(!Configuration::isImagesEnabled())
            {
                return;
            }

            foreach(FileAttachmentManager::getRecordsByEvidence($evidenceUuid) as $attachment)
            {
                self::review($attachment->getUuid());
            }
        }

        /**
         * Returns the image of an attachment prepared for the endpoint
         *
         * @param FileAttachmentRecord $attachment The attachment
         * @return PreparedImage|null The image, null if it's not an image or it can't be read
         */
        public static function load(FileAttachmentRecord $attachment): ?PreparedImage
        {
            if(array_key_exists($attachment->getUuid(), self::$prepared))
            {
                return self::$prepared[$attachment->getUuid()];
            }

            // The file is untrusted whatever its record says: it's read with a hard limit, recognized by its signature,
            // and only decoded in the media sandbox
            $image = null;
            if(Configuration::isImagesEnabled())
            {
                $data = UntrustedContent::readAttachment($attachment, ImagePreparer::MAX_FILE_SIZE);
                if($data !== null && ImagePreparer::isImage($data))
                {
                    $image = ImagePreparer::prepare($data);
                }
            }

            return self::$prepared[$attachment->getUuid()] = $image;
        }

        /**
         * Returns the image of a file submitted for scanning prepared for the endpoint. The image is not kept in this
         * process, the file only exists for the request
         *
         * @param ScanFile $file The file
         * @return PreparedImage|null The image, null if it's not an image or it can't be read
         */
        public static function loadScanFile(ScanFile $file): ?PreparedImage
        {
            if(!Configuration::isImagesEnabled())
            {
                return null;
            }

            $data = UntrustedContent::readScanFile($file, ImagePreparer::MAX_FILE_SIZE);
            return $data !== null && ImagePreparer::isImage($data) ? ImagePreparer::prepare($data) : null;
        }

        /**
         * Returns what the model found in an image
         *
         * @param string $sha256 The SHA-256 of the image file
         * @return ImageRecord|null The findings, null if the image was never judged
         * @throws DatabaseOperationException If there is an error with the database
         */
        public static function getFindings(string $sha256): ?ImageRecord
        {
            if(!array_key_exists($sha256, self::$findings))
            {
                self::$findings[$sha256] = ImageManager::getImage($sha256);
            }

            return self::$findings[$sha256];
        }

        /**
         * Forgets the images prepared and the findings read in this process, eg; after a review
         *
         * @return void
         */
        public static function clearCache(): void
        {
            self::$prepared = [];
            self::$findings = [];
        }
    }
