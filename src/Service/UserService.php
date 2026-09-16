<?php

namespace App\Service;


use App\Entity\StoredFile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class UserService
{
    private const HISTORY_PAGE_SIZE = 12;

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

    public function changePassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->uphi->hashPassword($user, $plainPassword));
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
ORDER BY dyear DESC, dmonth ASC';
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
ORDER BY dyear DESC, dmonth ASC';
        $stmt = $this->em->getConnection()->prepare($sql);

        return $this->buildDateTree($stmt->executeQuery()->fetchAllAssociative());
    }

    /**
     * Rows arrive ordered by the queries (years newest first, months January to December);
     * insertion order is kept so the calendar renders in that same order.
     */
    private function buildDateTree(array $report)
    {
        $result = [];
        foreach ($report as $dateTreeReport) {
            $result[$dateTreeReport['dyear']][$dateTreeReport['dmonth']] = $dateTreeReport['dcount'];
        }

        return $result;
    }

    public function getUserUploadHistoryPage(User $user, $cursor, $orderBy, $filter)
    {
        $limit = self::HISTORY_PAGE_SIZE;
        $fileRepo = $this->em->getRepository(StoredFile::class);
        $pageFiles = $fileRepo->getUserUploadHistoryPage($user, $cursor, $limit, $orderBy, $filter);

        return $this->buildHistoryPage($pageFiles, $limit);
    }

    public function getAnonymousUploadHistoryPage($cursor, $orderBy, $filter)
    {
        $limit = self::HISTORY_PAGE_SIZE;
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