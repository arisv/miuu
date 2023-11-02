<?php

namespace App\Service;


use App\Entity\StoredFile;
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
        $user->setRole(User::ROLE_USER);
        $user->setActive(true);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
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
GROUP BY YEAR(FROM_UNIXTIME(filestorage.date)), MONTH(FROM_UNIXTIME(filestorage.date))';
        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->bindValue("user", $user->getId());

        $stmt->execute();
        $report = $stmt->fetchAll();

        $result = [];
        foreach ($report as $dateTreeReport) {
            $result[$dateTreeReport['dyear']][$dateTreeReport['dmonth']] = $dateTreeReport['dcount'];
        }

        return $result;
    }

    public function getUserUploadHistoryPage(User $user, $cursor, $orderBy, $filter)
    {
        $result = [
            'files' => []
        ];
        $limit = 12;
        $fileRepo = $this->em->getRepository(StoredFile::class);
        $pageFiles = $fileRepo->getUserUploadHistoryPage($user, $cursor, $limit, $orderBy, $filter);

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
        $stmt->execute();
        $namedUsers = $stmt->fetchAll();

        $stmt = $this->em->getConnection()->prepare($anonUserQuery);
        $stmt->execute();
        $anonUsers = $stmt->fetchAll();

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