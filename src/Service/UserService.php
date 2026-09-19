<?php

namespace App\Service;


use App\Entity\StoredFile;
use App\Entity\UploadRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class UserService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CursorService $cursorService,
        private UserPasswordHasherInterface $uphi
        )
    {
    }

    public function createUser($userData)
    {
        $user = new User();
        $user->setLogin($userData['login']);
        $user->setEmail($userData['email']);
        $user->setPassword($this->uphi->hashPassword(
            $user,
            $userData['password']
        ));
        $user->setRemoteToken($this->generateToken());
        $user->setRole($userData['role'] ?? User::ROLE_USER);
        $user->setActive(true);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    public function setUserActive(User $actor, int $userId, bool $active): User
    {
        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            throw new \Exception("User {$userId} not found");
        }
        if ($user->getId() === $actor->getId()) {
            throw new \Exception("You cannot change your own active status");
        }
        $user->setActive($active);
        $this->em->flush();
        return $user;
    }

    public function findUser(int $userId): ?User
    {
        return $this->em->getRepository(User::class)->find($userId);
    }

    public function setUserRole(User $actor, int $userId, int $role): User
    {
        if (!in_array($role, [User::ROLE_USER, User::ROLE_ADMIN], true)) {
            throw new \Exception("Unknown role {$role}");
        }
        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            throw new \Exception("User {$userId} not found");
        }
        if ($user->getId() === $actor->getId()) {
            throw new \Exception("You cannot change your own role");
        }
        $user->setRole($role);
        $this->em->flush();
        return $user;
    }

    /**
     * Removes the account. Its upload records go with it, so the files become anonymous uploads
     * (still visible to admins); with $markFiles they are additionally marked for deletion.
     */
    public function deleteUser(User $actor, int $userId, bool $markFiles): array
    {
        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            throw new \Exception("User {$userId} not found");
        }
        if ($user->getId() === $actor->getId()) {
            throw new \Exception("You cannot delete your own account");
        }
        $records = $this->em->getRepository(UploadRecord::class)->findBy(['user' => $user]);
        $marked = 0;
        foreach ($records as $record) {
            if ($markFiles) {
                $file = $record->getImage();
                if (!$file->markedForDeletion()) {
                    $file->setVisibilityStatus(false);
                    $file->setMarkedForDeletionAt(new \DateTime());
                    $marked++;
                }
            }
            $this->em->remove($record);
        }
        $login = $user->getLogin();
        $this->em->remove($user);
        $this->em->flush();
        return ['login' => $login, 'files' => count($records), 'marked' => $marked];
    }

    public function changePassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->uphi->hashPassword($user, $plainPassword));
        $this->em->flush();
    }

    public function changeEmail(User $user, string $email): void
    {
        $user->setEmail(trim($email));
        $this->em->flush();
    }

    /**
     * Admin-side reset: replaces the password with a random one and returns it in plain text
     * so it can be shown to the admin exactly once.
     */
    public function resetPassword(User $actor, int $userId): array
    {
        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            throw new \Exception("User {$userId} not found");
        }
        if ($user->getId() === $actor->getId()) {
            throw new \Exception("Use your profile page to change your own password");
        }
        $plainPassword = bin2hex(random_bytes(6));
        $this->changePassword($user, $plainPassword);
        return [$user, $plainPassword];
    }

    /** Replaces the remote upload token; the previous one stops working immediately. */
    public function regenerateToken(User $user): string
    {
        $token = $this->generateToken();
        $user->setRemoteToken($token);
        $this->em->flush();
        return $token;
    }

    public function generateToken()
    {
        do {
            $token = bin2hex(openssl_random_pseudo_bytes(16));
            $exists = $this->em->getRepository(User::class)->findOneBy([
                'remoteToken' => $token
            ]);
        } while ($exists);
        return $token;
    }

    public function getUploadDateTree(User $user)
    {
        $sql = 'SELECT YEAR(FROM_UNIXTIME(filestorage.date)) as dyear, MONTH(FROM_UNIXTIME(filestorage.date)) as dmonth, COUNT(filestorage.id) as dcount FROM uploadlog
JOIN filestorage ON uploadlog.image_id = filestorage.id AND uploadlog.user_id = :user
GROUP BY YEAR(FROM_UNIXTIME(filestorage.date)), MONTH(FROM_UNIXTIME(filestorage.date))
ORDER BY dyear DESC, dmonth DESC';
        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->bindValue("user", $user->getId());

        return $this->buildDateTree($stmt->executeQuery()->fetchAllAssociative());
    }

    public function getAnonymousUploadDateTree()
    {
        $sql = 'SELECT YEAR(FROM_UNIXTIME(filestorage.date)) as dyear, MONTH(FROM_UNIXTIME(filestorage.date)) as dmonth, COUNT(filestorage.id) as dcount FROM filestorage
LEFT JOIN uploadlog ON uploadlog.image_id = filestorage.id
WHERE uploadlog.user_id IS NULL
GROUP BY YEAR(FROM_UNIXTIME(filestorage.date)), MONTH(FROM_UNIXTIME(filestorage.date))
ORDER BY dyear DESC, dmonth DESC';
        $stmt = $this->em->getConnection()->prepare($sql);

        return $this->buildDateTree($stmt->executeQuery()->fetchAllAssociative());
    }

    /**
     * Rows arrive newest first (ORDER BY in the queries); insertion order is kept
     * so the calendar lists recent years and months at the top.
     */
    private function buildDateTree(array $report)
    {
        $result = [];
        foreach ($report as $dateTreeReport) {
            $result[$dateTreeReport['dyear']][$dateTreeReport['dmonth']] = $dateTreeReport['dcount'];
        }

        return $result;
    }

    public function getUserUploadHistoryPage(User $user, $cursor, $orderBy, $filter, int $limit = CursorService::DEFAULT_PAGE_SIZE)
    {
        $fileRepo = $this->em->getRepository(StoredFile::class);
        $pageFiles = $fileRepo->getUserUploadHistoryPage($user, $cursor, $limit, $orderBy, $filter);

        return $this->buildHistoryPage($pageFiles, $limit);
    }

    public function countUserUploadHistory(User $user, $filter): int
    {
        return $this->em->getRepository(StoredFile::class)->countUserUploadHistory($user, $filter);
    }

    public function countAnonymousUploadHistory($filter): int
    {
        return $this->em->getRepository(StoredFile::class)->countAnonymousUploadHistory($filter);
    }

    public function getAnonymousUploadHistoryPage($cursor, $orderBy, $filter, int $limit = CursorService::DEFAULT_PAGE_SIZE)
    {
        $fileRepo = $this->em->getRepository(StoredFile::class);
        $pageFiles = $fileRepo->getAnonymousUploadHistoryPage($cursor, $limit, $orderBy, $filter);

        return $this->buildHistoryPage($pageFiles, $limit);
    }

    /**
     * @param array $pageFiles UploadRecord[] or StoredFile[], one more than $limit when a next page exists
     */
    private function buildHistoryPage(array $pageFiles, int $limit)
    {
        $result = [
            'files' => []
        ];

        if (count($pageFiles) > $limit) {
            $result['hasNextPage'] = true;
        } else {
            $result['hasNextPage'] = false;
        }

        $used = 0;
        foreach ($pageFiles as $file) {
            if ($used < $limit) {
                $result['files'][] = $file;
            }
            $used++;
        }

        if (!empty($pageFiles)) {
            $lastElementOffset = (count($result['files']) - 1);
            $result['cursor'] = $this->cursorService->encodeCursor($pageFiles[$lastElementOffset]);
        }

        return $result;
    }

    public function getAllUserIndex()
    {
        $allUsers = $this->em->getRepository(User::class)->findAll();
        array_unshift($allUsers, $this->getDefaultUser());
        return $allUsers;
    }

    public function getDefaultUser()
    {
        $user = new User();
        $user->setId(0);
        $user->setLogin('Anonymous uploads');
        $user->setActive(true);
        return $user;
    }

    public function getStorageStats()
    {
        $namedUsersQuery = <<<SQL
SELECT uploadlog.image_id, filestorage.id, uploadlog.user_id, SUM(filestorage.internal_size) as total, COUNT(filestorage.id) as amount FROM uploadlog
JOIN filestorage ON uploadlog.image_id = filestorage.id
WHERE uploadlog.user_id IS NOT NULL
GROUP BY uploadlog.user_id
SQL;

        $anonUserQuery = <<<SQL
SELECT filestorage.id, uploadlog.image_id, uploadlog.user_id, SUM(filestorage.internal_size) as total, COUNT(filestorage.id) as amount FROM filestorage
LEFT OUTER JOIN uploadlog ON uploadlog.image_id = filestorage.id
WHERE uploadlog.user_id is null
GROUP BY uploadlog.user_id
SQL;
        $stmt = $this->em->getConnection()->prepare($namedUsersQuery);
        $namedUsers = $stmt->executeQuery()->fetchAllAssociative();;

        $stmt = $this->em->getConnection()->prepare($anonUserQuery);
        $anonUsers = $stmt->executeQuery()->fetchAllAssociative();;

        $result = [];

        foreach ($namedUsers as $namedUser) {
            $result[$namedUser['user_id']] = [
                'total' => $this->formatSize($namedUser['total']),
                'amount' => $namedUser['amount']
            ];

        }
        foreach ($anonUsers as $anonUser) {
            $result['0'] = [
                'total' => $this->formatSize($anonUser['total']),
                'amount' => $anonUser['amount']
            ];
        }

        return $result;
    }

    public static function formatSize($size, $pres = 2)
    {
        $names = array('B', 'KB', 'MB', 'G', 'T');
        $i = 0;
        while($size > 1024)
        {
            $size /= 1024;
            $i++;
        }
        return round($size, $pres) . ' ' . $names[$i];
    }

}