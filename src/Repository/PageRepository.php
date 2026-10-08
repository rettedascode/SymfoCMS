<?php

namespace App\Repository;

use App\Entity\Page;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Page>
 */
class PageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Page::class);
    }

    public function findHomepage(): ?Page
    {
        return $this->findOneBy(['homepage' => true]);
    }

    /**
     * @return list<Page>
     */
    public function findPublished(): array
    {
        return $this->findBy(['status' => ContentStatus::Published], ['title' => 'ASC']);
    }

    /**
     * Makes sure the given page is the only one flagged as homepage.
     */
    public function unsetOtherHomepages(Page $homepage): void
    {
        $qb = $this->createQueryBuilder('p')
            ->update()
            ->set('p.homepage', ':false')
            ->where('p.homepage = :true')
            ->setParameter('false', false)
            ->setParameter('true', true);

        if (null !== $homepage->getId()) {
            $qb->andWhere('p.id != :id')->setParameter('id', $homepage->getId());
        }

        $qb->getQuery()->execute();
    }
}
