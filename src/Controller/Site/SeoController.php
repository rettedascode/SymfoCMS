<?php

namespace App\Controller\Site;

use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use App\Service\ContentLocales;
use App\Service\SiteUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SeoController extends AbstractController
{
    /**
     * Lists every public URL of every language for search engines.
     */
    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function sitemap(PageRepository $pages, PostRepository $posts, CategoryRepository $categories, ContentLocales $locales, SiteUrls $siteUrls): Response
    {
        $urls = [];
        $homepages = [];

        foreach ($pages->findPublished() as $page) {
            if (!$locales->isEnabled($page->getLocale())) {
                continue;
            }
            if ($page->isHomepage()) {
                $homepages[$page->getLocale()] = true;
            }
            $urls[] = ['loc' => $siteUrls->content($page, absolute: true), 'lastmod' => $page->getUpdatedAt()];
        }

        foreach ($locales->all() as $locale) {
            if (!isset($homepages[$locale])) {
                $urls[] = ['loc' => $siteUrls->generate('app_home', [], $locale, absolute: true), 'lastmod' => null];
            }

            $publicPosts = $posts->findPublic($locale, 1, 5000);
            if ([] !== $publicPosts) {
                $urls[] = ['loc' => $siteUrls->generate('app_blog', [], $locale, absolute: true), 'lastmod' => $publicPosts[0]->getPublishedAt()];
            }

            foreach ($categories->findWithPublicPosts($locale) as $category) {
                $urls[] = ['loc' => $siteUrls->generate('app_blog_category', ['slug' => $category->getSlug()], $locale, absolute: true), 'lastmod' => null];
            }

            foreach ($publicPosts as $post) {
                $urls[] = ['loc' => $siteUrls->content($post, absolute: true), 'lastmod' => $post->getUpdatedAt()];
            }
        }

        $response = $this->render('site/sitemap.xml.twig', ['urls' => $urls]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /login',
            '',
            'Sitemap: '.$this->generateUrl('app_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL),
            '',
        ];

        return new Response(implode("\n", $lines), Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
