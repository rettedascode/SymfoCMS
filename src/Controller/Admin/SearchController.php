<?php

namespace App\Controller\Admin;

use App\Security\Voter\MediaVoter;
use App\Security\Voter\PageVoter;
use App\Security\Voter\PostVoter;
use App\Service\Search;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AdminController
{
    private const LIMIT = 30;

    /**
     * One search field for everything a person may work on: pages, posts and
     * images of all languages, drafts included. Results the user is not
     * allowed to edit are left out.
     */
    #[Route('/admin/search', name: 'admin_search', methods: ['GET'])]
    public function __invoke(Request $request, Search $search): Response
    {
        $query = trim(mb_substr((string) $request->query->get('q', ''), 0, 100));
        $terms = $search->terms($query);
        $found = $search->findForAdmin($terms, self::LIMIT);

        $pages = $this->isGranted(PageVoter::MANAGE) ? $found['pages'] : [];
        $posts = array_values(array_filter($found['posts'], fn (array $result): bool => $this->isGranted(PostVoter::EDIT, $result['entity'])));
        $media = $this->isGranted(MediaVoter::BROWSE) ? $found['media'] : [];

        return $this->render('admin/search/index.html.twig', [
            'query' => $query,
            'terms' => $terms,
            'too_short' => '' !== $query && [] === $terms,
            'pages' => $pages,
            'posts' => $posts,
            'media' => $media,
            'total' => \count($pages) + \count($posts) + \count($media),
        ]);
    }
}
