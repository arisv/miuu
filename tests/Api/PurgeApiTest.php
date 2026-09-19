<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The app's purge endpoints: the password gate, the happy path and the cancel eligibility. */
final class PurgeApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private PurgeFixtures $fixtures;
    private User $bob;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($em, $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->bob = $this->fixtures->user('bob', 'bob12345');
        $this->fixtures->file($this->bob);
        $this->fixtures->file($this->bob);

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'login' => 'bob', 'password' => 'bob12345', 'device_name' => 'phpunit', 'platform' => 'other',
        ]);
        self::assertResponseIsSuccessful();
        $this->token = json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    private function api(string $method, array $body = []): array
    {
        $this->client->jsonRequest($method, '/api/v1/me/purge', $body, ['HTTP_AUTHORIZATION' => "Bearer {$this->token}"]);
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testStatusStartsClean(): void
    {
        $data = $this->api('GET');
        self::assertResponseIsSuccessful();
        self::assertSame(['total' => 2, 'marked' => 0, 'active' => 2, 'pending' => false, 'can_cancel' => false], $data['purge']);
    }

    public function testPurgeNeedsTheCurrentPassword(): void
    {
        $this->api('POST', ['current_password' => 'wrong']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->api('GET')['purge']['marked'], 'a wrong password must not delete anything');

        $this->api('POST', []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->api('GET')['purge']['marked']);
    }

    public function testPurgeThenCancelRoundTrip(): void
    {
        $data = $this->api('POST', ['current_password' => 'bob12345']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $data['marked']);
        self::assertTrue($data['purge']['can_cancel']);

        $data = $this->api('DELETE');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $data['restored']);
        self::assertFalse($data['purge']['pending']);
    }

    public function testCancelWithoutAPendingPurgeIsRejected(): void
    {
        $data = $this->api('DELETE');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('purge_not_cancellable', $data['error']['code'] ?? $data['code'] ?? null);
    }

    public function testEndpointsRequireAuthentication(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/me/purge', ['current_password' => 'bob12345']);
        self::assertResponseStatusCodeSame(401);
    }
}
