<?php
namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

class UserRepository extends EntityRepository implements UserLoaderInterface
{
    public function loadUserByUsername($usernameOrEmail)
    {
        return $this->createQueryBuilder('u')
            ->where('u.login = :query OR u.email = :query')
            ->setParameter('query', $usernameOrEmail)
            ->getQuery()
            ->getOneOrNullResult();
    }
}