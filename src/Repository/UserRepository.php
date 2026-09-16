<?php
namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserRepository extends EntityRepository implements UserLoaderInterface
{
    /**
     * Login form accepts either the username or the email. Username is matched first so the
     * lookup is deterministic even if some email happened to equal another account's username.
     */
    public function loadUserByIdentifier(string $usernameOrEmail): ?UserInterface
    {
        $byLogin = $this->createQueryBuilder('u')
            ->where('u.login = :query')
            ->setParameter('query', $usernameOrEmail)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($byLogin) {
            return $byLogin;
        }

        return $this->createQueryBuilder('u')
            ->where('u.email = :query')
            ->setParameter('query', $usernameOrEmail)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveUserByToken($remoteToken)
    {
        return $this->createQueryBuilder('u')
            ->where('u.remoteToken = :token')
            ->setParameter('token', $remoteToken)
            ->andWhere('u.active = true')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findUsersByList($idList)
    {
        $users = $this->createQueryBuilder('u')
            ->where('u.id IN (:idList)')
            ->setParameter('idList', $idList)
            ->getQuery()
            ->getResult();
        $result = [];
        foreach ($users as $user) {
            $result[$user->getId()] = $user;
        }
        return $result;
    }
}