<?php

namespace App\Repository;

use App\Entity\Form;
use App\Entity\FormSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormSubmission>
 */
class FormSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormSubmission::class);
    }

    /**
     * @return list<FormSubmission> newest first
     */
    public function findForForm(Form $form, int $page = 1, int $perPage = 50): array
    {
        /** @var list<FormSubmission> $submissions */
        $submissions = $this->createQueryBuilder('s')
            ->where('s.form = :form')
            ->setParameter('form', $form)
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $submissions;
    }

    /**
     * @return array<int, int> form id => number of unread submissions
     */
    public function countUnreadByForm(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.form) AS form_id, COUNT(s.id) AS total')
            ->where('s.read = :unread')
            ->setParameter('unread', false)
            ->groupBy('s.form')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['form_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return int number of deleted submissions
     */
    public function deleteOlderThan(\DateTimeImmutable $limit): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.createdAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();
    }
}
