<?php

namespace App\Repository;

use App\Entity\Revision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Revision>
 */
class RevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Revision::class);
    }

    /**
     * Revisions of one page or post, newest first.
     *
     * @return list<Revision>
     */
    public function findForContent(string $contentType, int $contentId): array
    {
        /** @var list<Revision> $revisions */
        $revisions = $this->createQueryBuilder('r')
            ->leftJoin('r.author', 'author')->addSelect('author')
            ->where('r.contentType = :type')
            ->andWhere('r.contentId = :id')
            ->setParameter('type', $contentType)
            ->setParameter('id', $contentId)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $revisions;
    }

    public function deleteForContent(string $contentType, int $contentId): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.contentType = :type')
            ->andWhere('r.contentId = :id')
            ->setParameter('type', $contentType)
            ->setParameter('id', $contentId)
            ->getQuery()
            ->execute();
    }
}
