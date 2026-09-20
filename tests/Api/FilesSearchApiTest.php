<?php

namespace App\Tests\Api;

use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** GET /api/v1/files?q=: file-name search applied before sort and group, reflected in total_count. */
final class FilesSearchApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $fixtures = new PurgeFixtures($container->get(EntityManagerInterface::class), $container->get(UserPasswordHasherInterface::class));
        $fixtures->resetSchema();
        $alice = $fixtures->user('alice', 'alice123');
        $fixtures->file($alice, false, 'Holiday_2024.png');
        $fixtures->file($alice, false, 'holiday notes.txt');
        $fixtures->file($alice, false, 'IMG_0001.jpg');
        $fixtures->file($alice, true, 'IMG_0002 (deleted).jpg');

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'login' => 'alice', 'password' => 'alice123', 'device_name' => 'phpunit', 'platform' => 'other',
        ]);
        self::assertResponseIsSuccessful();
        $this->token = json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    private function names(string $query): array
    {
        $this->client->jsonRequest('GET', '/api/v1/files?' . $query, [], ['HTTP_AUTHORIZATION' => "Bearer {$this->token}"]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $names = array_column($data['files'], 'name');
        sort($names);
        self::assertSame(count($names), $data['total_count'], 'total_count follows the filter');
        return ['names' => $names, 'q' => $data['q']];
    }

    public function testWordsMatchAnywhereCaseInsensitively(): void
    {
        $r = $this->names('q=holiday');
        self::assertSame(['Holiday_2024.png', 'holiday notes.txt'], $r['names']);
        self::assertSame('holiday', $r['q']);
    }

    public function testEveryWordMustMatch(): void
    {
        self::assertSame(['holiday notes.txt'], $this->names('q=notes+holiday')['names']);
        self::assertSame(['IMG_0001.jpg'], $this->names('q=img+0001')['names']);
    }

    public function testGlobs(): void
    {
        self::assertSame(['Holiday_2024.png'], $this->names('q=holi*2024')['names']);
        self::assertSame(['IMG_0001.jpg', 'IMG_0002 (deleted).jpg'], $this->names('q=img_000?')['names']);
    }

    public function testLikeMetacharactersAreLiteral(): void
    {
        self::assertSame([], $this->names('q=100%25')['names']);
        self::assertSame(['Holiday_2024.png', 'IMG_0001.jpg', 'IMG_0002 (deleted).jpg'], $this->names('q=_')['names'], 'underscore is literal, not a wildcard');
    }

    public function testBlankQueryIsIgnoredAndEchoedAsNull(): void
    {
        $r = $this->names('q=+++');
        self::assertCount(4, $r['names']);
        self::assertNull($r['q']);
    }

    public function testSearchComposesWithSortAndGroup(): void
    {
        $r = $this->names('q=img&sort=name&order=asc&group=type');
        self::assertSame(['IMG_0001.jpg', 'IMG_0002 (deleted).jpg'], $r['names']);
    }
}
