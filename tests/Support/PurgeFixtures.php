<?php

namespace App\Tests\Support;

use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Fresh SQLite schema plus users with a few files each, for the purge tests. */
final class PurgeFixtures
{
    private int $serial = 0;

    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher)
    {
    }

    public function resetSchema(): void
    {
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function user(string $login, string $password, int $role = User::ROLE_USER): User
    {
        $user = new User();
        $user->setLogin($login);
        $user->setEmail("{$login}@test.local");
        $user->setRole($role);
        $user->setActive(true);
        $user->setRemoteToken(bin2hex(random_bytes(16)));
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    /** A stored file owned by the user (via an upload record); $marked puts it in the trash. */
    public function file(User $owner, bool $marked = false, ?string $name = null): StoredFile
    {
        $n = ++$this->serial;
        $file = new StoredFile();
        $file->setOriginalName($name ?? "file-{$n}.png");
        $file->setInternalName("internal-{$n}");
        $file->setCustomUrl("custom{$n}");
        $file->setServiceUrl("service{$n}");
        $file->setOriginalExtension('png');
        $file->setInternalMimetype('image/png');
        $file->setInternalSize(100 + $n);
        $file->setDate(1_700_000_000 + $n);
        $file->setVisibilityStatus(!$marked);
        $file->setMarkedForDeletionAt($marked ? new \DateTime('-1 minute') : null);
        $this->em->persist($file);

        $record = new UploadRecord();
        $record->setUser($owner);
        $record->setImage($file);
        $this->em->persist($record);
        $this->em->flush();
        return $file;
    }

    /** Fresh copies of the user's files straight from the database. */
    public function filesOf(User $owner): array
    {
        $this->em->clear();
        $records = $this->em->getRepository(UploadRecord::class)->findBy(['user' => $owner->getId()]);
        return array_map(fn (UploadRecord $r) => $r->getImage(), $records);
    }

    /** The user as the database has it now (the identity map is cleared first). */
    public function reload(User $user): User
    {
        $this->em->clear();
        return $this->em->getRepository(User::class)->find($user->getId());
    }
}
