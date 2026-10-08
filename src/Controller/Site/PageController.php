<?php

namespace App\Controller\Site;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Security\Voter\PageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    public function __construct(private readonly PageRepository $pages)
    {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        $page = $this->pages->findHomepage();

        // Without a visible homepage, greet visitors with a simple placeholder instead of an error.
        if (null === $page || !$this->isGranted(PageVoter::VIEW, $page)) {
            return $this->render('site/welcome.html.twig');
        }

        return $this->renderPage($page);
    }

    /**
     * Catch-all for pages. The negative priority lets every other route win.
     */
    #[Route('/{slug<[a-z0-9]+(?:-[a-z0-9]+)*>}', name: 'app_page', methods: ['GET'], priority: -100)]
    public function show(string $slug): Response
    {
        $page = $this->pages->findOneBy(['slug' => $slug]);

        // Drafts answer with 404 (not 403) so their existence is not revealed.
        if (null === $page || !$this->isGranted(PageVoter::VIEW, $page)) {
            throw $this->createNotFoundException();
        }

        if ($page->isHomepage() && $page->isPublic()) {
            return $this->redirectToRoute('app_home', status: Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->renderPage($page);
    }

    private function renderPage(Page $page): Response
    {
        $response = $this->render('site/page.html.twig', ['page' => $page]);

        if (!$page->isPublic()) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
