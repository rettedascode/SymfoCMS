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

    /**
     * Every language has its own homepage.
     */
    public function findHomepage(string $locale): ?Page
    {
        return $this->findOneBy(['homepage' => true, 'locale' => $locale]);
    }

    /**
     * Published pages of all languages.
     *
     * @return list<Page>
     */
    public function findPublished(): array
    {
        return $this->findBy(['status' => ContentStatus::Published], ['locale' => 'ASC', 'title' => 'ASC']);
    }

    /**
     * Makes sure the given page is the only homepage of its language.
     */
    public function unsetOtherHomepages(Page $homepage): void
    {
        $qb = $this->createQueryBuilder('p')
            ->update()
            ->set('p.homepage', ':false')
            ->where('p.homepage = :true')
            ->andWhere('p.locale = :locale')
            ->setParameter('false', false)
            ->setParameter('true', true)
            ->setParameter('locale', $homepage->getLocale());

        if (null !== $homepage->getId()) {
            $qb->andWhere('p.id != :id')->setParameter('id', $homepage->getId());
        }

        $qb->getQuery()->execute();
    }
}
