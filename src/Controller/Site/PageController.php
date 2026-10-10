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
    /** One or more slugs separated by slashes. */
    private const PATH = '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*';

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
     *
     * The route with the language prefix is tried first: since paths may contain slashes, "/de/kontakt"
     * would otherwise be read as the child page "kontakt" of a page "de". A prefix that is no language
     * of the website is handed back to the path by the LocaleSubscriber.
     */
    #[Route('/{slug}', name: 'app_page', requirements: ['slug' => self::PATH], methods: ['GET'], priority: -100)]
    #[Route('/{_locale}/{slug}', name: 'app_page.localized', requirements: ['_locale' => ContentLocales::PATTERN, 'slug' => self::PATH], methods: ['GET'], priority: -99)]
    public function show(string $slug): Response
    {
        // "$slug" is the whole path: for a child page the slugs of its parents come first ("about/team").
        $segments = explode('/', $slug);
        $page = $this->pages->findOneBy(['slug' => end($segments), 'locale' => $this->siteUrls->currentLocale()]);

        // A page only answers at its full address, not at a shorter or an older one.
        if (null !== $page && $page->getPath() !== $slug) {
            $page = null;
        }

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
