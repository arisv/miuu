<?php

namespace App\Service;


use App\Entity\StoredFile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class UserService
{
    private $em;
    private $encoder;

    public function __construct(EntityManagerInterface $em, UserPasswordEncoderInterface $encoder)
    {
        $this->em = $em;
        $this->encoder = $encoder;
    }

    public function createUser($userData)
    {
        $user = new User();
        $user->setLogin($userData['login']);
        $user->setEmail($userData['email']);
        $user->setPassword($this->encoder->encodePassword(
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

    public function getUserUploadHistoryPage(User $user, $beforeOffset, $afterOffset)
    {
        $result = [
            'files' => []
        ];
        $limit = 9;
        $fileRepo = $this->em->getRepository(StoredFile::class);
        $pageFiles = $fileRepo->getUserUploadHistoryPage($user, $beforeOffset, $afterOffset, $limit);

        if (count($pageFiles) > $limit) {
            $result['hasNextPage'] = true;
        } else {
            $result['hasNextPage'] = false;
        }


        if ($afterOffset > 0) {
            $result['prev'] = 'after';

        } else if ($beforeOffset > 0) {
            $result['prev'] = 'before';

        } else {
            $result['prev'] = 'home';
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
            if ($beforeOffset > 0) //before returns files in inverse order
            {
                $result['files'] = array_reverse($result['files']);
                $result['beforeId'] = $pageFiles[$lastElementOffset]->getUploadId();
                $result['afterId'] = $pageFiles[0]->getUploadId();
            } else {
                $result['beforeId'] = $pageFiles[0]->getUploadId();
                $result['afterId'] = $pageFiles[$lastElementOffset]->getUploadId();
            }

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
SELECT uploadlog.image_id, filestorage.id, uploadlog.user_id, SUM(filestorage.internal_size) as total FROM uploadlog
JOIN filestorage ON uploadlog.image_id = filestorage.id
WHERE uploadlog.user_id IS NOT NULL
GROUP BY uploadlog.user_id
SQL;

        $anonUserQuery = <<<SQL
SELECT filestorage.id, uploadlog.image_id, uploadlog.user_id, SUM(filestorage.internal_size) as total FROM filestorage
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
            $result[$namedUser['user_id']] = $this->formatSize($namedUser['total']);
        }
        foreach ($anonUsers as $anonUser) {
            $result['0'] = $this->formatSize($anonUser['total']);
        }

        return $result;
    }

    private function formatSize($size, $pres = 2)
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