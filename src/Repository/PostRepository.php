<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * Posts visible to the public: published and not scheduled for the future.
     */
    public function createPublicQueryBuilder(?Category $category = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('status', ContentStatus::Published)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        if (null !== $category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }

        return $qb;
    }

    /**
     * @return list<Post>
     */
    public function findPublic(int $page, int $perPage, ?Category $category = null): array
    {
        /** @var list<Post> $posts */
        $posts = $this->createPublicQueryBuilder($category)
            ->leftJoin('p.coverImage', 'cover')->addSelect('cover')
            ->leftJoin('p.category', 'cat')->addSelect('cat')
            ->leftJoin('p.author', 'author')->addSelect('author')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $posts;
    }

    public function countPublic(?Category $category = null): int
    {
        return (int) $this->createPublicQueryBuilder($category)
            ->select('COUNT(p.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Posts for the admin list, newest first. Pass an author to limit the list to their posts.
     *
     * @return list<Post>
     */
    public function findForAdmin(?User $author = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'cat')->addSelect('cat')
            ->leftJoin('p.author', 'author')->addSelect('author')
            ->orderBy('p.updatedAt', 'DESC');

        if (null !== $author) {
            $qb->where('p.author = :author')->setParameter('author', $author);
        }

        /** @var list<Post> $posts */
        $posts = $qb->getQuery()->getResult();

        return $posts;
    }
}
