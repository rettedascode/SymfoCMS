<?php

namespace App\Controller\Site;

use App\Service\ContentLocales;
use App\Service\Search;
use App\Service\SiteUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    private const PER_PAGE = 10;

    /**
     * Searches the published pages and posts of the current language.
     */
    #[Route('/search', name: 'app_search', methods: ['GET'])]
    #[Route('/{_locale}/search', name: 'app_search.localized', requirements: ['_locale' => ContentLocales::PATTERN], methods: ['GET'])]
    public function __invoke(Request $request, Search $search, SiteUrls $siteUrls): Response
    {
        $query = trim(mb_substr((string) $request->query->get('q', ''), 0, 100));
        $terms = $search->terms($query);
        $page = max(1, min(1000, $request->query->getInt('page', 1)));

        $found = $search->findPublic($terms, $siteUrls->currentLocale(), $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($found['total'] / self::PER_PAGE));
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('site/search.html.twig', [
            'query' => $query,
            'terms' => $terms,
            'too_short' => '' !== $query && [] === $terms,
            'results' => $found['results'],
            'total' => $found['total'],
            'page' => $page,
            'pages' => $pages,
        ]);
        // Result lists are of no use in the results of a search engine.
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
