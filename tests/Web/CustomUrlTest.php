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

/** /u/{alias}: media streams like the raw link, other files render the file page in place (with owner controls). */
final class CustomUrlTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PurgeFixtures $fixtures;
    private FileAliasService $aliases;
    private string $storage;
    private User $alice;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Act as a browser: without Accept: text/html, /u/ serves the file itself (see RawClientsTest).
        $this->client->setServerParameter('HTTP_ACCEPT', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($this->em, $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->aliases = $container->get(FileAliasService::class);
        $this->storage = dirname(__DIR__, 2) . '/' . $_ENV['STORAGE_DIR'];
        (new Filesystem())->remove($this->storage);
        $this->alice = $this->fixtures->user('alice', 'alice123');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storage);
        parent::tearDown();
    }

    private function pdf(string $name = 'report.pdf'): StoredFile
    {
        $file = $this->fixtures->file($this->alice, false, $name);
        $file->setInternalMimetype('application/pdf');
        $file->setOriginalExtension('pdf');
        $this->em->flush();
        return $file;
    }

    public function testMediaStreamsWithoutASessionAndIsCachedBriefly(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'photo.png');
        $this->fixtures->storeOnDisk($file, $this->storage, 'png bytes');
        $this->aliases->set($file, 'Photo');

        $this->client->request('GET', '/u/Photo');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertStringContainsString('photo.png', $response->headers->get('Content-Disposition'));
        self::assertSame([], $response->headers->getCookies());
        self::assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('s-maxage=300', $response->headers->get('Cache-Control'));
    }

    public function testSignedInMediaIsKeptOutOfSharedCaches(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'photo.png');
        $this->fixtures->storeOnDisk($file, $this->storage, 'png bytes');
        $this->aliases->set($file, 'Photo');
        $this->client->loginUser($this->alice);

        $this->client->request('GET', '/u/Photo');

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringNotContainsString('s-maxage', $cacheControl);
    }

    public function testOtherFilesRenderThePageInPlaceWithOwnerControls(): void
    {
        $this->aliases->set($this->pdf(), 'Docs');

        $crawler = $this->client->request('GET', '/u/Docs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1.view-title', 'report.pdf');
        self::assertSame('http://localhost/u/Docs', $crawler->filter('.view-actions .clipbutton')->attr('data-clipboard-text'));
        self::assertCount(0, $crawler->filter('[data-view-delete]'), 'visitors get the public view');

        $this->client->loginUser($this->fixtures->user('bob', 'bob12345'));
        self::assertCount(0, $this->client->request('GET', '/u/Docs')->filter('[data-view-delete]'), 'so do other users');

        $this->client->loginUser($this->alice);
        self::assertCount(1, $this->client->request('GET', '/u/Docs')->filter('[data-view-delete]'), 'the owner can manage the file');
    }

    public function testRawLinksStartingWithUStayOutOfTheSessionFirewall(): void
    {
        $file = $this->fixtures->file($this->alice, false, 'photo.png');
        $file->setCustomUrl('uabc123');
        $this->em->flush();
        $this->fixtures->storeOnDisk($file, $this->storage, 'png bytes');
        $this->aliases->set($file, 'Photo');

        $this->client->request('GET', '/uabc123.png');
        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('.images', (string) $this->client->getRequest()->attributes->get('_firewall_context'));

        $this->client->request('GET', '/u/Photo');
        self::assertStringEndsWith('.main', (string) $this->client->getRequest()->attributes->get('_firewall_context'));
    }

    public function testFilePageStillShowsOwnerControlsAndSharesTheCustomUrl(): void
    {
        $file = $this->pdf();
        $this->client->loginUser($this->alice);

        $crawler = $this->client->request('GET', '/v/' . $file->getCustomUrl() . '.pdf');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-view-delete]'));
        self::assertStringEndsWith('/v/' . $file->getCustomUrl() . '.pdf', $crawler->filter('.view-actions .clipbutton')->attr('data-clipboard-text'));

        $this->aliases->set($file, 'Docs');
        $crawler = $this->client->request('GET', '/v/' . $file->getCustomUrl() . '.pdf');
        self::assertSame('http://localhost/u/Docs', $crawler->filter('.view-actions .clipbutton')->attr('data-clipboard-text'));
    }

    public function testUnservedAliasesAre404(): void
    {
        $this->aliases->set($this->pdf(), 'Trip');
        $trashed = $this->pdf('trashed.pdf');
        $this->aliases->set($trashed, 'Trashed');
        $trashed->setVisibilityStatus(false);
        $trashed->setMarkedForDeletionAt(new \DateTime());
        $bob = $this->fixtures->user('bob', 'bob12345');
        $this->aliases->set($this->fixtures->file($bob), 'Purging');
        $bob->setPurgeTs(time() + 3600);
        $this->em->flush();

        foreach (['/u/trip', '/u/Trashed', '/u/Purging', '/u/Nope', '/u/a-b', '/u/Trip.pdf'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $this->client->request('GET', '/u/Trip');
        self::assertResponseIsSuccessful();
    }
}
