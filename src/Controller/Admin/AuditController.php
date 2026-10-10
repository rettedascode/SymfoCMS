<?php

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Entity\AuditEntry;
use App\Entity\Form;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\SharedBlock;
use App\Entity\User;
use App\Security\Voter\AdminAreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The activity log: who changed what, and when. For administrators.
 */
#[IsGranted(AdminAreaVoter::VIEW_AUDIT_LOG)]
final class AuditController extends AdminController
{
    private const PER_PAGE = 50;

    /** Where the name of a thing in the log leads to, as long as the thing still exists. */
    private const LINKS = [
        'page' => [Page::class, 'admin_page_edit'],
        'post' => [Post::class, 'admin_post_edit'],
        'media' => [Media::class, 'admin_media_edit'],
        'user' => [User::class, 'admin_user_edit'],
        'form' => [Form::class, 'admin_form_edit'],
        'shared_block' => [SharedBlock::class, 'admin_shared_block_edit'],
    ];

    #[Route('/admin/activity', name: 'admin_audit_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, AuditLogger $logger): Response
    {
        // Old entries are removed whenever somebody looks at the log; no cron job needed.
        $logger->purge();

        $user = $request->query->getString('user');
        $type = $request->query->getString('type');
        $subjectId = $request->query->getInt('id');
        $search = trim(mb_substr($request->query->getString('q'), 0, 100));
        $page = max(1, $request->query->getInt('page', 1));

        $qb = $entityManager->createQueryBuilder()->from(AuditEntry::class, 'e');
        if ('system' === $user) {
            $qb->andWhere('e.userId IS NULL');
        } elseif (ctype_digit($user)) {
            $qb->andWhere('e.userId = :user')->setParameter('user', (int) $user);
        }
        if ('' !== $type) {
            $qb->andWhere('e.subjectType = :type')->setParameter('type', $type);
            if ($subjectId > 0) {
                $qb->andWhere('e.subjectId = :id')->setParameter('id', $subjectId);
            }
        }
        if ('' !== $search) {
            $qb->andWhere("LOWER(e.subjectLabel) LIKE :search ESCAPE '!'")
                ->setParameter('search', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%');
        }

        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        /** @var list<AuditEntry> $entries */
        $entries = $qb->select('e')
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult((min($page, $pages) - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()
            ->getResult();

        /** @var list<string> $types */
        $types = $entityManager->createQuery('SELECT DISTINCT e.subjectType FROM '.AuditEntry::class.' e ORDER BY e.subjectType')->getSingleColumnResult();

        return $this->render('admin/audit/index.html.twig', [
            'entries' => $entries,
            'links' => $this->links($entries, $entityManager),
            'users' => $entityManager->getRepository(User::class)->findBy([], ['name' => 'ASC']),
            'types' => $types,
            'filter' => ['user' => $user, 'type' => $type, 'id' => $subjectId, 'q' => $search],
            'total' => $total,
            'page' => min($page, $pages),
            'pages' => $pages,
        ]);
    }

    /**
     * @param list<AuditEntry> $entries
     *
     * @return array<string, string> "type:id" => address of the edit page, for things that still exist
     */
    private function links(array $entries, EntityManagerInterface $entityManager): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if (null !== $entry->getSubjectId() && isset(self::LINKS[$entry->getSubjectType()])) {
                $ids[$entry->getSubjectType()][$entry->getSubjectId()] = true;
            }
        }

        $links = [];
        foreach ($ids as $type => $typeIds) {
            [$class, $route] = self::LINKS[$type];
            foreach ($entityManager->getRepository($class)->findBy(['id' => array_keys($typeIds)]) as $entity) {
                $links[$type.':'.$entity->getId()] = $this->generateUrl($route, ['id' => $entity->getId()]);
            }
        }

        return $links;
    }
}
