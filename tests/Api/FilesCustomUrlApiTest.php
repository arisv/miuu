<?php

namespace App\Tests\Api;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** POST|DELETE /files/{id}/custom-url, and custom_url / urls.custom / urls.share on file objects. */
final class FilesCustomUrlApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private PurgeFixtures $fixtures;
    /** @var array<string, string> */
    private array $tokens = [];
    private StoredFile $image;
    private StoredFile $pdf;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($em, $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $alice = $this->fixtures->user('alice', 'alice123');
        $this->fixtures->user('bob', 'bob12345');
        $this->fixtures->user('admin', 'admin123', User::ROLE_ADMIN);
        $this->image = $this->fixtures->file($alice, false, 'photo.png');
        $this->pdf = $this->fixtures->file($alice, false, 'report.pdf');
        $this->pdf->setInternalMimetype('application/pdf');
        $this->pdf->setOriginalExtension('pdf');
        $em->flush();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(string $login, string $method, string $path, array $body = []): array
    {
        if (!isset($this->tokens[$login])) {
            $password = ['alice' => 'alice123', 'bob' => 'bob12345', 'admin' => 'admin123'][$login];
            $this->client->jsonRequest('POST', '/api/v1/auth/login', ['login' => $login, 'password' => $password, 'device_name' => 'phpunit', 'platform' => 'other']);
            $this->tokens[$login] = json_decode($this->client->getResponse()->getContent(), true)['token'];
        }
        $this->client->jsonRequest($method, '/api/v1' . $path, $body, ['HTTP_AUTHORIZATION' => "Bearer {$this->tokens[$login]}"]);
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function set(StoredFile $file, string $alias, string $login = 'alice'): array
    {
        return $this->call($login, 'POST', "/files/{$file->getId()}/custom-url", ['custom_url' => $alias]);
    }

    public function testSettingACustomUrlMakesItTheShareLink(): void
    {
        $file = $this->set($this->pdf, 'Report2026')['file'];

        self::assertResponseIsSuccessful();
        self::assertSame('Report2026', $file['custom_url']);
        self::assertSame('http://localhost/u/Report2026', $file['urls']['custom']);
        self::assertSame($file['urls']['custom'], $file['urls']['share']);
    }

    public function testListCarriesCustomUrls(): void
    {
        $this->set($this->image, 'Photo');
        $files = $this->call('alice', 'GET', '/files')['files'];
        $byName = array_column($files, 'custom_url', 'name');
        ksort($byName);
        self::assertSame(['photo.png' => 'Photo', 'report.pdf' => null], $byName);
    }

    public function testRemovingFallsBackToTheDefaultShareLink(): void
    {
        $this->set($this->image, 'Photo');
        $this->set($this->pdf, 'Report');

        $image = $this->call('alice', 'DELETE', "/files/{$this->image->getId()}/custom-url")['file'];
        self::assertResponseIsSuccessful();
        self::assertNull($image['custom_url']);
        self::assertNull($image['urls']['custom']);
        self::assertSame($image['urls']['direct'], $image['urls']['share']);

        $pdf = $this->call('alice', 'DELETE', "/files/{$this->pdf->getId()}/custom-url")['file'];
        self::assertSame($pdf['urls']['view'], $pdf['urls']['share']);
        $this->call('alice', 'DELETE', "/files/{$this->pdf->getId()}/custom-url");
        self::assertResponseIsSuccessful();
    }

    public function testTakenAndInvalidAliases(): void
    {
        $this->set($this->image, 'Photo');
        $carols = $this->fixtures->file($this->fixtures->user('carol', 'carol123'), false, 'x.png');

        $data = $this->set($carols, 'Photo', 'admin');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('alias_taken', $data['error']['code']);
        self::assertArrayHasKey('custom_url', $data['error']['details']);

        $data = $this->set($this->pdf, 'not-valid');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $data['error']['code']);
        self::assertArrayHasKey('custom_url', $data['error']['details']);
    }

    public function testAnonymousFilesCannotHaveOne(): void
    {
        $data = $this->set($this->fixtures->anonymousFile(), 'Anon', 'admin');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_owner', $data['error']['code']);
    }

    public function testOtherUsersFilesAre404(): void
    {
        $this->set($this->image, 'Mine', 'bob');
        self::assertResponseStatusCodeSame(404);
        $this->call('bob', 'DELETE', "/files/{$this->image->getId()}/custom-url");
        self::assertResponseStatusCodeSame(404);
    }

    public function testServerAdvertisesCustomUrls(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/server');
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['custom_urls']);
        self::assertSame(64, $data['custom_url_max_length']);
    }
}
