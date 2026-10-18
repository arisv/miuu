<?php

namespace App\Tests\Service;

use App\Entity\FileAlias;
use App\Entity\User;
use App\Exception\FileEditException;
use App\Service\FileAliasService;
use App\Service\FileService;
use App\Service\UserService;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Custom URLs: one per owned file, unique and case sensitive, freed on change, removal and deletion. */
final class FileAliasServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PurgeFixtures $fixtures;
    private FileAliasService $aliases;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($this->em, $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->aliases = $container->get(FileAliasService::class);
        $this->alice = $this->fixtures->user('alice', 'alice123');
        $this->bob = $this->fixtures->user('bob', 'bob12345');
    }

    private function assertRejected(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail("expected {$reason}");
        } catch (FileEditException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testSetAndResolve(): void
    {
        $file = $this->fixtures->file($this->alice);

        $alias = $this->aliases->set($file, 'Trip2026');

        self::assertSame('Trip2026', $this->aliases->aliasFor($file));
        self::assertSame($file, $this->aliases->resolve('Trip2026'));
        self::assertSame($alias, $this->aliases->set($file, 'Trip2026'), 'setting the same alias again is a no-op');
        self::assertStringEndsWith('/u/Trip2026', $this->aliases->url('Trip2026'));
    }

    public function testAliasesAreUniqueButCaseSensitive(): void
    {
        $this->aliases->set($this->fixtures->file($this->alice), 'Trip');

        $this->assertRejected(FileEditException::ALIAS_TAKEN, fn () => $this->aliases->set($this->fixtures->file($this->bob), 'Trip'));
        $other = $this->fixtures->file($this->bob);
        $this->aliases->set($other, 'trip');
        self::assertSame($other, $this->aliases->resolve('trip'));
    }

    public function testInvalidAliases(): void
    {
        $file = $this->fixtures->file($this->alice);
        foreach (['', 'a-b', 'with space', 'ünï', str_repeat('a', 65)] as $alias) {
            $this->assertRejected(FileEditException::ALIAS_INVALID, fn () => $this->aliases->set($file, $alias));
        }
        $this->aliases->set($file, str_repeat('a', 64));
        self::assertNull($this->aliases->resolve('a-b'));
    }

    public function testAnonymousFilesCannotHaveOne(): void
    {
        $this->assertRejected(FileEditException::NO_OWNER, fn () => $this->aliases->set($this->fixtures->anonymousFile(), 'Mine'));
    }

    public function testChangingOrRemovingFreesTheOldAlias(): void
    {
        $file = $this->fixtures->file($this->alice);
        $this->aliases->set($file, 'Trip');
        $this->aliases->set($file, 'Tour');

        self::assertNull($this->aliases->resolve('Trip'));
        $bobs = $this->fixtures->file($this->bob);
        $this->aliases->set($bobs, 'Trip');

        self::assertTrue($this->aliases->remove($file));
        self::assertFalse($this->aliases->remove($file));
        self::assertNull($this->aliases->aliasFor($file));
        $this->aliases->set($bobs, 'Tour');
        self::assertSame(1, $this->em->getRepository(FileAlias::class)->count([]), 'one row per file');
    }

    public function testAliasesForMapsAPageInOneCall(): void
    {
        $a = $this->fixtures->file($this->alice);
        $b = $this->fixtures->file($this->alice);
        $c = $this->fixtures->file($this->alice);
        $this->aliases->set($a, 'A');
        $this->aliases->set($c, 'C');

        self::assertSame([$a->getId() => 'A', $c->getId() => 'C'], $this->aliases->aliasesFor([$a, $b, $c]));
        self::assertSame([], $this->aliases->aliasesFor([]));
    }

    public function testTrashedAndPurgingFilesDoNotResolve(): void
    {
        $file = $this->fixtures->file($this->alice);
        $this->aliases->set($file, 'Trashed');
        static::getContainer()->get(FileService::class)->markForDeletion($file);
        self::assertNull($this->aliases->resolve('Trashed'));

        $this->aliases->set($this->fixtures->file($this->bob), 'Purging');
        $this->bob->setPurgeTs(time() + 3600);
        $this->em->flush();
        self::assertNull($this->aliases->resolve('Purging'));
    }

    public function testDeletingTheFileFreesTheAlias(): void
    {
        $file = $this->fixtures->file($this->alice);
        $this->aliases->set($file, 'Gone');

        static::getContainer()->get(FileService::class)->deleteFileFromStorage($file);

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(FileAlias::class)->count([]));
        $this->aliases->set($this->fixtures->file($this->fixtures->reload($this->bob)), 'Gone');
    }

    public function testDeletingTheOwnerFreesTheAlias(): void
    {
        $admin = $this->fixtures->user('admin', 'admin123', User::ROLE_ADMIN);
        $this->aliases->set($this->fixtures->file($this->alice), 'Gone');

        static::getContainer()->get(UserService::class)->deleteUser($admin, $this->alice->getId(), false);

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(FileAlias::class)->count([]));
        self::assertNull($this->aliases->resolve('Gone'));
    }
}
