<?php

namespace App\Repository;

use App\Entity\MenuItem;
use App\Enum\MenuLocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MenuItem>
 */
class MenuItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuItem::class);
    }

    /**
     * Top-level items of one menu (a location in one language) in display order,
     * with their children and link targets loaded.
     *
     * @return list<MenuItem>
     */
    public function findTree(MenuLocation $location, string $locale): array
    {
        /** @var list<MenuItem> $items */
        $items = $this->createQueryBuilder('m')
            ->leftJoin('m.children', 'c')->addSelect('c')
            ->leftJoin('m.page', 'page')->addSelect('page')
            ->leftJoin('m.post', 'post')->addSelect('post')
            ->leftJoin('c.page', 'cpage')->addSelect('cpage')
            ->leftJoin('c.post', 'cpost')->addSelect('cpost')
            ->where('m.location = :location')
            ->andWhere('m.locale = :locale')
            ->andWhere('m.parent IS NULL')
            ->setParameter('location', $location)
            ->setParameter('locale', $locale)
            ->orderBy('m.position', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function deleteMenu(MenuLocation $location, string $locale): void
    {
        // Children first, so the delete also works on databases without cascading foreign keys.
        foreach (['IS NOT NULL', 'IS NULL'] as $parentCondition) {
            $this->createQueryBuilder('m')
                ->delete()
                ->where('m.location = :location')
                ->andWhere('m.locale = :locale')
                ->andWhere('m.parent '.$parentCondition)
                ->setParameter('location', $location)
                ->setParameter('locale', $locale)
                ->getQuery()
                ->execute();
        }
    }
}
