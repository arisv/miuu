<?php

namespace App\Tests\Web;

use App\Entity\StoredFile;
use App\Entity\User;
use App\Service\FileAliasService;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** /v/ and /u/ answer tools and embeds with the file itself, browsers and preview bots with the page. */
final class RawClientsTest extends WebTestCase
{
    private const CURL = ['HTTP_USER_AGENT' => 'curl/8.11.1', 'HTTP_ACCEPT' => '*/*'];
    private const BROWSER_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PurgeFixtures $fixtures;
    private string $storage;
    private User $alice;
    private StoredFile $image;
    private StoredFile $text;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The test client sends no Accept header, which reads as a tool; requests act as a browser unless they say otherwise.
        $this->client->setServerParameter('HTTP_ACCEPT', self::BROWSER_ACCEPT);
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($this->em, $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->storage = dirname(__DIR__, 2) . '/' . $_ENV['STORAGE_DIR'];
        (new Filesystem())->remove($this->storage);
        $this->alice = $this->fixtures->user('alice', 'alice123');

        $this->image = $this->fixtures->file($this->alice, false, 'photo.png');
        $this->fixtures->storeOnDisk($this->image, $this->storage, 'png bytes');
        $this->text = $this->fixtures->file($this->alice, false, 'notes.txt');
        $this->text->setInternalMimetype('text/plain');
        $this->text->setOriginalExtension('txt');
        $this->em->flush();
        $this->fixtures->storeOnDisk($this->text, $this->storage, "hello\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storage);
        parent::tearDown();
    }

    private function viewUrl(StoredFile $file): string
    {
        return '/v/' . $file->getCustomUrl() . '.' . $file->getOriginalExtension();
    }

    private function assertVaries(): void
    {
        $vary = $this->client->getResponse()->getVary();
        self::assertContains('Accept', $vary);
        self::assertContains('User-Agent', $vary);
    }

    public function testFilePageServesTheFileToTools(): void
    {
        $this->client->request('GET', $this->viewUrl($this->image), [], [], self::CURL);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringStartsWith('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('s-maxage=86400', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $this->assertVaries();

        $this->client->request('GET', $this->viewUrl($this->text), [], [], ['HTTP_USER_AGENT' => 'Wget/1.25.0', 'HTTP_ACCEPT' => '*/*']);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testFilePageServesTheFileToEmbeds(): void
    {
        $this->client->request('GET', $this->viewUrl($this->image), [], [], ['HTTP_ACCEPT' => 'image/avif,image/webp,image/png,image/*;q=0.8,*/*;q=0.5']);
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testBrowsersAndPreviewBotsGetThePage(): void
    {
        $this->client->request('GET', $this->viewUrl($this->image));
        self::assertSelectorTextSame('h1.view-title', 'photo.png');
        $this->assertVaries();

        $this->client->request('GET', $this->viewUrl($this->image), [], [], ['HTTP_USER_AGENT' => 'continuwuity/26.9.1 (embedbot; facebookexternalhit/1.1)', 'HTTP_ACCEPT' => '*/*']);
        self::assertSelectorTextSame('h1.view-title', 'photo.png');
    }

    public function testTrashedFilesAreNotServedRawEvenToTheOwner(): void
    {
        $this->image->setVisibilityStatus(false);
        $this->image->setMarkedForDeletionAt(new \DateTime());
        $this->em->flush();
        $this->client->loginUser($this->alice);

        $this->client->request('GET', $this->viewUrl($this->image), [], [], self::CURL);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->viewUrl($this->image));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-view-delete]');
    }

    public function testSignedInToolsAreKeptOutOfSharedCaches(): void
    {
        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->viewUrl($this->image), [], [], self::CURL);
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringNotContainsString('s-maxage', $cacheControl);
    }

    public function testCustomUrlsOfOtherFilesServeTheFileToTools(): void
    {
        $aliases = static::getContainer()->get(FileAliasService::class);
        $aliases->set($this->text, 'Notes');
        $aliases->set($this->image, 'Photo');

        $this->client->request('GET', '/u/Notes', [], [], self::CURL);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=300', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $this->assertVaries();

        $this->client->request('GET', '/u/Notes');
        self::assertSelectorTextSame('h1.view-title', 'notes.txt');
        $this->assertVaries();

        $this->client->request('GET', '/u/Photo');
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertNotContains('User-Agent', $this->client->getResponse()->getVary(), 'media at /u/ is the file for everyone');
    }
}
