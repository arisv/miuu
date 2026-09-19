<?php

namespace App\Tests\Command;

use App\Command\PurgeCommand;
use App\Entity\User;
use App\Tests\Support\PurgeFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `app:purge` destroys the files of users whose deadline has passed, in batches, under a per-user
 * lock, and clears their purge_ts. Users whose deadline is in the future, and users locked by
 * another run, are left alone.
 */
final class PurgeCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PurgeFixtures $fixtures;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->fixtures = new PurgeFixtures($this->em, $container->get(UserPasswordHasherInterface::class));
        $this->fixtures->resetSchema();
        $this->tester = new CommandTester((new Application(self::$kernel))->find('app:purge'));
    }

    private function due(User $user, int $secondsAgo = 60): void
    {
        $user->setPurgeTs(time() - $secondsAgo);
        $this->em->flush();
    }

    public function testDueUsersLoseEveryFileInBatchesAndAreUnfrozen(): void
    {
        $bob = $this->fixtures->user('bob', 'bob12345');
        $alice = $this->fixtures->user('alice', 'alice123');
        for ($i = 0; $i < 7; $i++) {
            $this->fixtures->file($bob, marked: $i === 0);
        }
        $this->fixtures->file($alice);
        $this->due($bob);

        $this->tester->execute(['--batch-size' => 3], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('bob: purge complete, 7 files deleted', $this->tester->getDisplay());
        self::assertCount(0, $this->fixtures->filesOf($bob), $this->tester->getDisplay());
        self::assertCount(1, $this->fixtures->filesOf($alice), "alice's file survives");
        self::assertNull($this->fixtures->reload($bob)->getPurgeTs(), 'bob is unfrozen afterwards');
    }

    public function testFutureDeadlinesAreLeftAlone(): void
    {
        $bob = $this->fixtures->user('bob', 'bob12345');
        $this->fixtures->file($bob);
        $bob->setPurgeTs(time() + 600);
        $this->em->flush();

        $this->tester->execute([]);

        $this->tester->assertCommandIsSuccessful();
        self::assertCount(1, $this->fixtures->filesOf($bob));
        self::assertNotNull($this->fixtures->reload($bob)->getPurgeTs());
    }

    public function testAUserLockedByAnotherRunIsSkippedButOthersAreProcessed(): void
    {
        $bob = $this->fixtures->user('bob', 'bob12345');
        $carol = $this->fixtures->user('carol', 'carol123');
        $this->fixtures->file($bob);
        $this->fixtures->file($carol);
        $this->due($bob);
        $this->due($carol);

        /** @var LockFactory $locks */
        $locks = static::getContainer()->get(LockFactory::class);
        $held = $locks->createLock(PurgeCommand::LOCK_PREFIX . 'bob', PurgeCommand::LOCK_TTL);
        self::assertTrue($held->acquire(), 'simulate a run still working on bob');

        $this->tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('bob: another run is purging this user, skipping', $this->tester->getDisplay());
        self::assertCount(1, $this->fixtures->filesOf($bob), 'bob is untouched by this run');
        self::assertNotNull($this->fixtures->reload($bob)->getPurgeTs(), 'and still due for the next one');
        self::assertCount(0, $this->fixtures->filesOf($carol), 'carol was processed anyway');
        self::assertNull($this->fixtures->reload($carol)->getPurgeTs());
        $held->release();
    }

    public function testDryRunChangesNothing(): void
    {
        $bob = $this->fixtures->user('bob', 'bob12345');
        $this->fixtures->file($bob);
        $this->due($bob);

        $this->tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('1 files would be deleted', $this->tester->getDisplay());
        self::assertCount(1, $this->fixtures->filesOf($bob));
        self::assertNotNull($this->fixtures->reload($bob)->getPurgeTs());
    }
}
