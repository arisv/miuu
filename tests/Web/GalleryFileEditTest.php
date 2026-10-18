<?php

namespace App\Tests\Web;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Service\FileAliasService;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The gallery's rename and custom-URL endpoints (session + CSRF header) and the tile data they rely on. */
final class GalleryFileEditTest extends WebTestCase
{
    private KernelBrowser $client;
    private PurgeFixtures $fixtures;
    private User $alice;
    private StoredFile $file;
    private StoredFile $bobsFile;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->fixtures = new PurgeFixtures($container->get(EntityManagerInterface::class), $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->alice = $this->fixtures->user('alice', 'alice123');
        $this->file = $this->fixtures->file($this->alice, false, 'IMG_0001.jpg');
        $this->bobsFile = $this->fixtures->file($this->fixtures->user('bob', 'bob12345'), false, 'bob.png');
    }

    /** Logs alice in and returns the CSRF token the gallery page hands to its script. */
    private function openGallery(): string
    {
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', '/manage/mypics/');
        self::assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter('#previewModal')->attr('data-file-edit-custom-urls'));
        return (string) $crawler->filter('#previewModal')->attr('data-file-edit-token');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body = [], ?string $token = null): array
    {
        $server = $token !== null ? ['HTTP_X_CSRF_TOKEN' => $token] : [];
        $this->client->jsonRequest($method, '/endpoint/files' . $path, $body, $server);
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testChangesNeedTheCsrfToken(): void
    {
        $this->openGallery();

        $data = $this->call('POST', "/{$this->file->getId()}/rename", ['base_name' => 'Holiday'], 'wrong');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('csrf_invalid', $data['error']['code']);
        $this->call('POST', "/{$this->file->getId()}/rename", ['base_name' => 'Holiday']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRenameHistoryAndRevertAnswerLikeTheApi(): void
    {
        $token = $this->openGallery();

        $data = $this->call('POST', "/{$this->file->getId()}/rename", ['base_name' => 'Holiday'], $token);
        self::assertResponseIsSuccessful();
        self::assertTrue($data['changed']);
        self::assertSame('Holiday.jpg', $data['file']['name']);

        $data = $this->call('POST', "/{$this->file->getId()}/rename", ['base_name' => 'a/b'], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('base_name', $data['error']['details']);

        $history = $this->call('GET', "/{$this->file->getId()}/name-history")['history'];
        self::assertCount(1, $history);
        $data = $this->call('POST', "/{$this->file->getId()}/name-history/{$history[0]['id']}/revert", [], $token);
        self::assertSame('IMG_0001.jpg', $data['file']['name']);
    }

    public function testOtherUsersFilesAre404(): void
    {
        $token = $this->openGallery();
        $data = $this->call('POST', "/{$this->bobsFile->getId()}/rename", ['base_name' => 'mine'], $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $data['error']['code']);
    }

    public function testVisitorsAreSentToTheLoginPage(): void
    {
        $this->call('POST', "/{$this->file->getId()}/rename", ['base_name' => 'x']);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCustomUrlsReachTheTiles(): void
    {
        $token = $this->openGallery();
        $data = $this->call('POST', "/{$this->file->getId()}/custom-url", ['custom_url' => 'Holiday'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost/u/Holiday', $data['file']['urls']['share']);

        $tile = $this->client->request('GET', '/manage/mypics/')->filter(".itembox[data-file-id=\"{$this->file->getId()}\"]");
        self::assertSame('Holiday', $tile->attr('data-file-alias'));
        self::assertSame('http://localhost/u/Holiday', $tile->attr('data-preview-share'));
        self::assertSame('IMG_0001', $tile->attr('data-name-base'));
        self::assertSame('jpg', $tile->attr('data-name-ext'));

        $this->client->request('GET', '/endpoint/user_next_files_page/');
        $cards = implode('', json_decode($this->client->getResponse()->getContent(), true)['rendered']);
        self::assertStringContainsString('data-file-alias="Holiday"', $cards);

        $this->call('DELETE', "/{$this->file->getId()}/custom-url", [], $token);
        self::assertResponseIsSuccessful();
        self::assertNull(static::getContainer()->get(FileAliasService::class)->aliasFor($this->file));
    }

    public function testAnonymousUploadsPageHasNoCustomUrls(): void
    {
        $this->fixtures->anonymousFile();
        $this->client->loginUser($this->fixtures->user('admin', 'admin123', User::ROLE_ADMIN));
        $crawler = $this->client->request('GET', '/manage/admin/anonymous/');
        self::assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter('#previewModal')->attr('data-file-edit-custom-urls'));
    }
}
