<?php

namespace App\Controller\Site;

use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SeoController extends AbstractController
{
    /**
     * Lists every public URL for search engines.
     */
    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function sitemap(PageRepository $pages, PostRepository $posts, CategoryRepository $categories): Response
    {
        $urls = [];
        $hasHomepage = false;

        foreach ($pages->findPublished() as $page) {
            $hasHomepage = $hasHomepage || $page->isHomepage();
            $urls[] = [
                'loc' => $page->isHomepage()
                    ? $this->generateUrl('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL)
                    : $this->generateUrl('app_page', ['slug' => $page->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => $page->getUpdatedAt(),
            ];
        }

        if (!$hasHomepage) {
            $urls[] = ['loc' => $this->generateUrl('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => null];
        }

        $publicPosts = $posts->findPublic(1, 5000);
        if ([] !== $publicPosts) {
            $urls[] = ['loc' => $this->generateUrl('app_blog', [], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => $publicPosts[0]->getPublishedAt()];
        }

        foreach ($categories->findWithPublicPosts() as $category) {
            $urls[] = ['loc' => $this->generateUrl('app_blog_category', ['slug' => $category->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => null];
        }

        foreach ($publicPosts as $post) {
            $urls[] = [
                'loc' => $this->generateUrl('app_blog_post', ['slug' => $post->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => $post->getUpdatedAt(),
            ];
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
