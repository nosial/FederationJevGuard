<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationJevGuard\Tests;

    use FederationJevGuard\Classes\ImagePreparer;
    use FederationJevGuard\Classes\MediaSandbox;
    use PHPUnit\Framework\TestCase;

    /**
     * Images are only decoded in the media sandbox, a separate PHP process with GD. The test images are generated here,
     * the hostile ones are crafted to be rejected without being decoded by this process
     */
    class ImagePreparerTest extends TestCase
    {
        protected function setUp(): void
        {
            if(!extension_loaded('gd') || !MediaSandbox::isAvailable())
            {
                $this->markTestSkipped('PHP\'s GD extension and the media sandbox (proc_open and the PHP command-line binary) are required');
            }
        }

        private static function image(int $width, int $height, string $format='png'): string
        {
            $image = imagecreatetruecolor($width, $height);
            imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
            ob_start();
            match($format)
            {
                'gif' => imagegif($image),
                'jpeg' => imagejpeg($image),
                default => imagepng($image),
            };

            return (string)ob_get_clean();
        }

        public function testLargeImagesAreScaledDown(): void
        {
            $prepared = ImagePreparer::prepare(self::image(4000, 3000));

            $this->assertNotNull($prepared);
            $this->assertSame(1568, $prepared->getWidth());
            $this->assertSame(1176, $prepared->getHeight());
            $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($prepared->getData())[2]);
        }

        public function testSmallImagesAreScaledUp(): void
        {
            $prepared = ImagePreparer::prepare(self::image(100, 50, 'gif'));

            $this->assertNotNull($prepared);
            $this->assertSame(560, $prepared->getWidth());
            $this->assertSame(280, $prepared->getHeight());
        }

        public function testNarrowImagesArePadded(): void
        {
            $prepared = ImagePreparer::prepare(self::image(3000, 100, 'jpeg'));

            $this->assertNotNull($prepared);
            $this->assertSame(1568, $prepared->getWidth());
            $this->assertSame(280, $prepared->getHeight());
        }

        public function testTheHashIsOfTheOriginalFile(): void
        {
            $data = self::image(400, 400);
            $prepared = ImagePreparer::prepare($data);

            $this->assertSame(hash('sha256', $data), $prepared->getSha256());
            $this->assertStringStartsWith('jevguard-image-', $prepared->getReference());
        }

        public function testNothingOfTheOriginalFileSurvives(): void
        {
            // A payload appended to an image (eg; a polyglot) is gone after the re-encoding
            $payload = '<?php system($_GET["c"]); ?>';
            $prepared = ImagePreparer::prepare(self::image(400, 400) . $payload);

            $this->assertNotNull($prepared);
            $this->assertStringNotContainsString($payload, $prepared->getData());
        }

        public function testDecompressionBombsAreRejected(): void
        {
            // A PNG header that claims 60000 x 60000 pixels
            $bomb = "\x89PNG\r\n\x1A\n" . pack('N', 13) . 'IHDR' . pack('NN', 60000, 60000) . "\x08\x02\x00\x00\x00" . pack('N', 0) . str_repeat("\0", 64);

            $this->assertTrue(ImagePreparer::isImage($bomb));
            $this->assertNull(ImagePreparer::prepare($bomb));
        }

        public function testBrokenAndForeignFilesAreRejected(): void
        {
            $this->assertNull(ImagePreparer::prepare(substr(self::image(400, 400), 0, 200)));
            $this->assertNull(ImagePreparer::prepare("\xFF\xD8\xFF" . random_bytes(512)));
            $this->assertNull(ImagePreparer::prepare('%PDF-1.7 ' . str_repeat('x', 100)));
            $this->assertNull(ImagePreparer::prepare(''));
            $this->assertNull(ImagePreparer::prepare(str_repeat('a', ImagePreparer::MAX_FILE_SIZE + 1)));
        }

        public function testImagesAreRecognizedByTheirSignature(): void
        {
            $this->assertTrue(ImagePreparer::isImage(self::image(10, 10)));
            $this->assertTrue(ImagePreparer::isImage('RIFF' . pack('V', 100) . 'WEBPVP8 '));
            $this->assertFalse(ImagePreparer::isImage('%PDF-1.7 not an image'));
            $this->assertFalse(ImagePreparer::isImage("<svg xmlns='http://www.w3.org/2000/svg'></svg>"));
        }
    }
