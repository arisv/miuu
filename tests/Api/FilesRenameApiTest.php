<?php

namespace App\Tests\Api;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** POST /files/{id}/rename, GET /files/{id}/name-history and POST …/name-history/{entry}/revert. */
final class FilesRenameApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private PurgeFixtures $fixtures;
    private string $token;
    private StoredFile $file;
    private StoredFile $bobsFile;
    private User $bob;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->fixtures = new PurgeFixtures($container->get(EntityManagerInterface::class), $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $alice = $this->fixtures->user('alice', 'alice123');
        $this->file = $this->fixtures->file($alice, false, 'IMG_0001.jpg');
        $this->bob = $this->fixtures->user('bob', 'bob12345');
        $this->bobsFile = $this->fixtures->file($this->bob, false, 'bob.png');

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'login' => 'alice', 'password' => 'alice123', 'device_name' => 'phpunit', 'platform' => 'other',
        ]);
        $this->token = json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body = []): array
    {
        $this->client->jsonRequest($method, '/api/v1' . $path, $body, ['HTTP_AUTHORIZATION' => "Bearer {$this->token}"]);
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function rename(string $baseName, ?StoredFile $file = null): array
    {
        return $this->call('POST', '/files/' . ($file ?? $this->file)->getId() . '/rename', ['base_name' => $baseName]);
    }

    public function testRenameKeepsTheExtension(): void
    {
        $data = $this->rename('Holiday');

        self::assertResponseIsSuccessful();
        self::assertTrue($data['changed']);
        self::assertSame('Holiday.jpg', $data['file']['name']);
        self::assertSame('Holiday', $data['file']['base_name']);
        self::assertSame('jpg', $data['file']['name_extension']);
        self::assertSame(['old_name' => 'IMG_0001.jpg', 'new_name' => 'Holiday.jpg', 'revert' => false, 'renamed_by' => 'alice'],
            array_intersect_key($data['entry'], array_flip(['old_name', 'new_name', 'revert', 'renamed_by'])));
    }

    public function testInvalidNamesAreRejected(): void
    {
        foreach (['', 'a/b', str_repeat('a', 252)] as $baseName) {
            $data = $this->rename($baseName);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('validation_failed', $data['error']['code']);
            self::assertArrayHasKey('base_name', $data['error']['details']);
        }
    }

    public function testUnchangedNameIsANoOp(): void
    {
        $data = $this->rename('IMG_0001');
        self::assertResponseIsSuccessful();
        self::assertFalse($data['changed']);
        self::assertNull($data['entry']);
    }

    public function testHistoryAndRevert(): void
    {
        $first = $this->rename('b')['entry'];
        $this->rename('c');

        $data = $this->call('POST', "/files/{$this->file->getId()}/name-history/{$first['id']}/revert");
        self::assertResponseIsSuccessful();
        self::assertSame('IMG_0001.jpg', $data['file']['name']);
        self::assertTrue($data['entry']['revert']);

        $history = $this->call('GET', "/files/{$this->file->getId()}/name-history")['history'];
        self::assertSame(['IMG_0001.jpg', 'c.jpg', 'b.jpg'], array_column($history, 'new_name'));
        self::assertCount(1, $this->call('GET', "/files/{$this->file->getId()}/name-history?limit=1")['history']);
    }

    public function testUnknownHistoryEntryIs404(): void
    {
        $data = $this->call('POST', "/files/{$this->file->getId()}/name-history/999/revert");
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $data['error']['code']);
    }

    public function testOtherUsersFilesAre404(): void
    {
        $id = $this->bobsFile->getId();
        $this->rename('mine', $this->bobsFile);
        self::assertResponseStatusCodeSame(404);
        $this->call('GET', "/files/{$id}/name-history");
        self::assertResponseStatusCodeSame(404);
        $this->call('POST', "/files/{$id}/name-history/1/revert");
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['bob.png'], array_map(fn (StoredFile $f) => $f->getOriginalName(), $this->fixtures->filesOf($this->bob)));
    }

    public function testServerAdvertisesRename(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/server');
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['rename']);
        self::assertSame(255, $data['name_max_bytes']);
    }
}
