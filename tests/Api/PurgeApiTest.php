<?php

namespace App\Tests\Api;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The app's purge endpoints: password gate, the frozen account while pending, cancel eligibility. */
final class PurgeApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private PurgeFixtures $fixtures;
    private User $bob;
    private StoredFile $file;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($em, $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->bob = $this->fixtures->user('bob', 'bob12345');
        $this->file = $this->fixtures->file($this->bob);
        $this->fixtures->file($this->bob);

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'login' => 'bob', 'password' => 'bob12345', 'device_name' => 'phpunit', 'platform' => 'other',
        ]);
        self::assertResponseIsSuccessful();
        $this->token = json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    private function api(string $method, string $path, array $body = []): array
    {
        $this->client->jsonRequest($method, $path, $body, ['HTTP_AUTHORIZATION' => "Bearer {$this->token}"]);
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testStatusStartsClean(): void
    {
        $data = $this->api('GET', '/api/v1/me/purge');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['pending' => false, 'purge_at' => null, 'total' => 2, 'marked' => 0, 'active' => 2, 'can_cancel' => false],
            $data['purge']
        );
    }

    public function testPurgeNeedsTheCurrentPassword(): void
    {
        $this->api('POST', '/api/v1/me/purge', ['current_password' => 'wrong']);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->api('GET', '/api/v1/me/purge')['purge']['pending'], 'a wrong password schedules nothing');

        $this->api('POST', '/api/v1/me/purge', []);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->api('GET', '/api/v1/me/purge')['purge']['pending']);
    }

    public function testPendingPurgeFreezesTheAccountUntilCancelled(): void
    {
        $data = $this->api('POST', '/api/v1/me/purge', ['current_password' => 'bob12345']);
        self::assertResponseIsSuccessful();
        self::assertTrue($data['purge']['pending']);
        self::assertNotNull($data['purge']['purge_at']);

        $list = $this->api('GET', '/api/v1/files');
        self::assertResponseIsSuccessful();
        self::assertSame([], $list['files'], 'the library is empty while pending');
        self::assertSame(0, $list['total_count']);
        self::assertFalse($list['has_next_page']);
        self::assertSame([], $this->api('GET', '/api/v1/files/dates')['months']);

        $this->api('GET', '/api/v1/files/' . $this->file->getId());
        self::assertResponseStatusCodeSame(404, 'own files answer 404 while pending');
        $this->api('DELETE', '/api/v1/files/' . $this->file->getId());
        self::assertResponseStatusCodeSame(404);

        $tmp = tempnam(sys_get_temp_dir(), 'purge');
        file_put_contents($tmp, 'hello');
        $this->client->request('POST', '/api/v1/files', [], ['file' => new UploadedFile($tmp, 'hello.txt', 'text/plain', null, true)], ['HTTP_AUTHORIZATION' => "Bearer {$this->token}"]);
        self::assertResponseStatusCodeSame(423, 'uploads are refused while pending');
        self::assertSame('purge_pending', json_decode($this->client->getResponse()->getContent(), true)['error']['code']);

        $data = $this->api('DELETE', '/api/v1/me/purge');
        self::assertResponseIsSuccessful();
        self::assertFalse($data['purge']['pending']);
        $list = $this->api('GET', '/api/v1/files');
        self::assertCount(2, $list['files'], 'everything is back after cancel');
        self::assertSame(2, $list['total_count']);
        $this->api('GET', '/api/v1/files/' . $this->file->getId());
        self::assertResponseIsSuccessful();
    }

    public function testCancelWithoutAPendingPurgeIsRejected(): void
    {
        $data = $this->api('DELETE', '/api/v1/me/purge');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('purge_not_cancellable', $data['error']['code']);
    }

    public function testEndpointsRequireAuthentication(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/me/purge', ['current_password' => 'bob12345']);
        self::assertResponseStatusCodeSame(401);
    }
}
