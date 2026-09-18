<?php

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<DeviceToken> */
class DeviceTokenRepository extends EntityRepository
{
    public function findActiveByHash(string $hash): ?DeviceToken
    {
        return $this->createQueryBuilder('t')
            ->addSelect('u')
            ->join('t.user', 'u')
            ->where('t.tokenHash = :hash')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return DeviceToken[] */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('t.lastUsedAt', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveForUser(User $user, int $id): ?DeviceToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.id = :id')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
