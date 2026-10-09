<?php

namespace App\Controller\Site;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\PostRepository;
use App\Security\Voter\PostVoter;
use App\Service\ContentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly CategoryRepository $categories,
        private readonly ContentManager $contentManager,
        #[Autowire(param: 'app.posts_per_page')]
        private readonly int $postsPerPage,
    ) {
    }

    #[Route('/blog', name: 'app_blog', methods: ['GET'])]
    #[Route('/blog/page/{page<[1-9]\d{0,5}>}', name: 'app_blog_page', methods: ['GET'])]
    public function index(int $page = 1): Response
    {
        return $this->listing($page, null);
    }

    #[Route('/blog/category/{slug}', name: 'app_blog_category', methods: ['GET'])]
    #[Route('/blog/category/{slug}/page/{page<[1-9]\d{0,5}>}', name: 'app_blog_category_page', methods: ['GET'])]
    public function category(string $slug, int $page = 1): Response
    {
        $category = $this->categories->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException();

        return $this->listing($page, $category);
    }

    #[Route('/blog/{slug<[a-z0-9]+(?:-[a-z0-9]+)*>}', name: 'app_blog_post', methods: ['GET'])]
    public function post(string $slug): Response
    {
        $post = $this->posts->findOneBy(['slug' => $slug]);

        // Drafts and scheduled posts answer with 404 for visitors so their existence is not revealed.
        if (null === $post || !$this->isGranted(PostVoter::VIEW, $post)) {
            throw $this->createNotFoundException();
        }

        $rendering = $this->contentManager->prepareRendering($post);
        $response = $this->render('site/blog/post.html.twig', ['post' => $post, 'blocks' => $rendering->getBlocks()] + $rendering->getTemplateVariables());
        if (!$post->isPublic()) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    #[Route('/feed.xml', name: 'app_feed', methods: ['GET'])]
    public function feed(): Response
    {
        $posts = $this->posts->findPublic(1, 20);

        $response = $this->render('site/blog/feed.xml.twig', [
            'posts' => $posts,
            'updated' => [] !== $posts ? $posts[0]->getPublishedAt() : new \DateTimeImmutable(),
        ]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');

        return $response;
    }

    private function listing(int $page, ?Category $category): Response
    {
        $total = $this->posts->countPublic($category);
        $pages = max(1, (int) ceil($total / $this->postsPerPage));

        // A page number beyond the last one does not exist.
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        return $this->render('site/blog/index.html.twig', [
            'posts' => $this->posts->findPublic($page, $this->postsPerPage, $category),
            'category' => $category,
            'categories' => $this->categories->findWithPublicPosts(),
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
