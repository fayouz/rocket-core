<?php

namespace Rocket\Core\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Rocket\Core\Entity\Secret;

/** @extends ServiceEntityRepository<Secret> */
class SecretRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Secret::class);
    }

    public function findOne(string $name, ?string $scope = null): ?Secret
    {
        return $this->findOneBy(['name' => $name, 'scope' => $scope]);
    }

    /** @return list<Secret> sorted by name; every scope when $scope is false */
    public function findByScope(string|null|false $scope = null): array
    {
        return false === $scope ? $this->findBy([], ['scope' => 'ASC', 'name' => 'ASC']) : $this->findBy(['scope' => $scope], ['name' => 'ASC']);
    }

    /** Recorded without loading nor flushing anything else. */
    public function touch(Secret $secret, \DateTimeImmutable $at): void
    {
        $this->getEntityManager()->getConnection()->update('secret', ['last_used_at' => $at->format('Y-m-d H:i:s')], ['id' => $secret->getId()->toRfc4122()]);
    }
}
