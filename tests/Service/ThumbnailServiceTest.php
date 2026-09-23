<?php

namespace App\Tests\Service;

use App\Service\FileService;
use App\Service\ThumbnailService;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ThumbnailServiceTest extends KernelTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->storage = static::getContainer()->getParameter('kernel.project_dir') . '/' . $_ENV['STORAGE_DIR'];
        (new Filesystem())->remove($this->storage);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storage);
        parent::tearDown();
    }

    public function testSidewaysJpegGetsAnUprightThumbnail(): void
    {
        $container = static::getContainer();
        $fixtures = new PurgeFixtures($container->get(EntityManagerInterface::class), $container->get(UserPasswordHasherInterface::class));
        $fixtures->resetSchema();
        // Stored 40x20, red left half, blue right half, Orientation 6: displayed 20x40 with red on top.
        $file = $container->get(FileService::class)->importLocalFile(__DIR__ . '/../Fixtures/media/orient-6.jpg', $fixtures->user('bob', 'bob12345'), 1_500_000_000);

        $thumb = imagecreatefromwebp($container->get(ThumbnailService::class)->generateThumbnail($file));

        self::assertSame([ThumbnailService::THUMBNAIL_WIDTH, 2 * ThumbnailService::THUMBNAIL_WIDTH], [imagesx($thumb), imagesy($thumb)]);
        $top = imagecolorsforindex($thumb, imagecolorat($thumb, 175, 150));
        $bottom = imagecolorsforindex($thumb, imagecolorat($thumb, 175, 550));
        self::assertGreaterThan(200, $top['red'], 'red half on top');
        self::assertGreaterThan(200, $bottom['blue'], 'blue half at the bottom');
    }
}
