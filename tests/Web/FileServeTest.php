<?php

namespace App\Tests\Web;

use App\Service\FileRenameService;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Raw file and thumbnail links carry the current name, even one Content-Disposition cannot hold verbatim. */
final class FileServeTest extends WebTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->storage = dirname(__DIR__, 2) . '/' . $_ENV['STORAGE_DIR'];
        (new Filesystem())->remove($this->storage);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storage);
        parent::tearDown();
    }

    public function testRenamedFileIsServedUnderItsNewName(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $fixtures = new PurgeFixtures($container->get(EntityManagerInterface::class), $container->get(UserPasswordHasherInterface::class));
        $fixtures->resetSchema();
        $file = $fixtures->file($fixtures->user('alice', 'alice123'), false, 'IMG_0001.png');
        $fixtures->storeOnDisk($file, $this->storage, 'png bytes', thumbnail: true);
        (new FileRenameService($container->get(EntityManagerInterface::class)))->rename($file, 'back\\slash', null);

        $client->request('GET', '/custom1.png');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('back_slash.png', $client->getResponse()->headers->get('Content-Disposition'));

        $client->request('GET', '/thumb/custom1.png');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('back_slash.png', $client->getResponse()->headers->get('Content-Disposition'));
    }
}
