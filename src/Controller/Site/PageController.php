<?php

namespace App\Controller\Site;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Security\Voter\PageVoter;
use App\Service\ContentLocales;
use App\Service\ContentManager;
use App\Service\SiteUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly ContentManager $contentManager,
        private readonly ContentLocales $locales,
        private readonly SiteUrls $siteUrls,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->renderHome($this->locales->default());
    }

    /**
     * "/de" is the homepage of another language - unless "de" is not a language
     * of this site, in which case it is treated like any other page address.
     */
    #[Route('/{_locale}', name: 'app_home.localized', requirements: ['_locale' => ContentLocales::PATTERN], methods: ['GET'], priority: -50)]
    public function localizedHome(string $_locale): Response
    {
        if ($this->locales->isDefault($_locale)) {
            return $this->redirectToRoute('app_home', status: Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->locales->isEnabled($_locale) ? $this->renderHome($_locale) : $this->show($_locale);
    }

    /**
     * Catch-all for pages. The negative priority lets every other route win.
     */
    #[Route('/{slug<[a-z0-9]+(?:-[a-z0-9]+)*>}', name: 'app_page', methods: ['GET'], priority: -100)]
    #[Route('/{_locale}/{slug<[a-z0-9]+(?:-[a-z0-9]+)*>}', name: 'app_page.localized', requirements: ['_locale' => ContentLocales::PATTERN], methods: ['GET'], priority: -100)]
    public function show(string $slug): Response
    {
        $page = $this->pages->findOneBy(['slug' => $slug, 'locale' => $this->siteUrls->currentLocale()]);

        // Drafts answer with 404 (not 403) so their existence is not revealed.
        if (null === $page || !$this->isGranted(PageVoter::VIEW, $page)) {
            throw $this->createNotFoundException();
        }

        if ($page->isHomepage() && $page->isPublic()) {
            return $this->redirect($this->siteUrls->content($page), Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->renderPage($page);
    }

    private function renderHome(string $locale): Response
    {
        $page = $this->pages->findHomepage($locale);

        // Without a visible homepage, greet visitors with a simple placeholder instead of an error.
        if (null === $page || !$this->isGranted(PageVoter::VIEW, $page)) {
            return $this->render('site/welcome.html.twig');
        }

        return $this->renderPage($page);
    }

    private function renderPage(Page $page): Response
    {
        $rendering = $this->contentManager->prepareRendering($page);
        $response = $this->render('site/page.html.twig', ['page' => $page, 'blocks' => $rendering->getBlocks()] + $rendering->getTemplateVariables());

        if (!$page->isPublic()) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
