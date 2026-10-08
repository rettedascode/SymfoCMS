<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Post;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Categories that have at least one publicly visible post.
     *
     * @return list<Category>
     */
    public function findWithPublicPosts(): array
    {
        /** @var list<Category> $categories */
        $categories = $this->createQueryBuilder('c')
            ->where('EXISTS (SELECT 1 FROM '.Post::class.' p WHERE p.category = c AND p.status = :status AND p.publishedAt <= :now)')
            ->setParameter('status', ContentStatus::Published)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $categories;
    }
}
