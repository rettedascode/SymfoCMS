<?php

namespace App\Controller\Api;

use App\Api\ApiSerializer;
use App\Api\OpenApi;
use App\Entity\Category;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\Post;
use App\Enum\ContentStatus;
use App\Enum\MenuLocation;
use App\Repository\CategoryRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use App\Service\ContentLocales;
use App\Service\MenuBuilder;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only JSON API for everything that is public on the website.
 *
 * It is switched off until an administrator enables it under "Settings";
 * App\EventSubscriber\ApiSubscriber answers 404 until then. The API is
 * described in App\Api\OpenApi, served at /api/v1/openapi.json.
 */
#[Route('/api/v1', name: 'api_', methods: ['GET'], format: 'json')]
final class ApiController extends AbstractController
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ApiSerializer $serializer,
        private readonly ContentLocales $locales,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): JsonResponse
    {
        $link = fn (string $route): string => $this->serializer->absolute($this->generateUrl($route));

        return $this->json([
            'name' => 'SymfoCMS read API',
            'version' => OpenApi::VERSION,
            'locales' => $this->locales->all(),
            'default_locale' => $this->locales->default(),
            'links' => [
                'pages' => $link('api_pages'),
                'posts' => $link('api_posts'),
                'categories' => $link('api_categories'),
                'menus' => $link('api_menus'),
                'media' => $link('api_media'),
                'openapi' => $link('api_openapi'),
            ],
        ]);
    }

    #[Route('/openapi.json', name: 'openapi')]
    public function openApi(OpenApi $openApi, Request $request): JsonResponse
    {
        return $this->json($openApi->describe($request->getSchemeAndHttpHost().$request->getBaseUrl()));
    }

    #[Route('/pages', name: 'pages')]
    public function pages(Request $request, PageRepository $pages): JsonResponse
    {
        $qb = $pages->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', ContentStatus::Published)
            ->orderBy('p.title', 'ASC')
            ->addOrderBy('p.id', 'ASC');
        $this->filter($qb, $request);

        return $this->paginated($request, $qb, fn (Page $page): array => $this->serializer->page($page));
    }

    #[Route('/pages/{id<\d+>}', name: 'page')]
    public function page(Page $page): JsonResponse
    {
        if (!$page->isPublic()) {
            throw $this->createNotFoundException();
        }

        return $this->json(['data' => $this->serializer->page($page, withContent: true)]);
    }

    #[Route('/posts', name: 'posts')]
    public function posts(Request $request, PostRepository $posts): JsonResponse
    {
        // Newest first; scheduled posts and drafts are not included.
        $qb = $posts->createPublicQueryBuilder(null);
        $this->filter($qb, $request);

        $category = $request->query->get('category');
        if (\is_string($category) && '' !== $category) {
            $qb->join('p.category', 'c')->andWhere('c.slug = :category')->setParameter('category', $category);
        }

        return $this->paginated($request, $qb, fn (Post $post): array => $this->serializer->post($post));
    }

    #[Route('/posts/{id<\d+>}', name: 'post')]
    public function post(Post $post): JsonResponse
    {
        if (!$post->isPublic()) {
            throw $this->createNotFoundException();
        }

        return $this->json(['data' => $this->serializer->post($post, withContent: true)]);
    }

    #[Route('/categories', name: 'categories')]
    public function categories(Request $request, CategoryRepository $categories): JsonResponse
    {
        $qb = $categories->createQueryBuilder('p')->orderBy('p.locale', 'ASC')->addOrderBy('p.position', 'ASC')->addOrderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC');
        $this->filter($qb, $request);

        return $this->paginated($request, $qb, fn (Category $category): array => $this->serializer->category($category));
    }

    /**
     * Both menus of one language. Entries that lead to content which is not public are left out.
     */
    #[Route('/menus', name: 'menus')]
    public function menus(Request $request, MenuBuilder $menus): JsonResponse
    {
        $locale = $this->locale($request) ?? $this->locales->default();

        $data = [];
        foreach (MenuLocation::cases() as $location) {
            $data[$location->value] = $this->serializer->menu($menus->build($location, $locale));
        }

        return $this->json(['data' => $data, 'meta' => ['locale' => $locale]]);
    }

    #[Route('/media', name: 'media')]
    public function media(Request $request, MediaRepository $media): JsonResponse
    {
        $qb = $media->createQueryBuilder('p')->orderBy('p.createdAt', 'DESC')->addOrderBy('p.id', 'DESC');

        return $this->paginated($request, $qb, fn (Media $item): array => $this->serializer->media($item));
    }

    #[Route('/media/{id<\d+>}', name: 'media_item')]
    public function mediaItem(Media $media): JsonResponse
    {
        return $this->json(['data' => $this->serializer->media($media)]);
    }

    /**
     * Applies the "locale" and "slug" filters shared by the lists.
     */
    private function filter(QueryBuilder $qb, Request $request): void
    {
        $locale = $this->locale($request);
        if (null !== $locale) {
            $qb->andWhere('p.locale = :locale')->setParameter('locale', $locale);
        }

        $slug = $request->query->get('slug');
        if (\is_string($slug) && '' !== $slug) {
            $qb->andWhere('p.slug = :slug')->setParameter('slug', $slug);
        }
    }

    private function locale(Request $request): ?string
    {
        $locale = $request->query->get('locale');
        if (!\is_string($locale) || '' === $locale) {
            return null;
        }

        if (!$this->locales->isEnabled($locale)) {
            throw new BadRequestHttpException(\sprintf('"%s" is not a language of this website. Available: %s.', $locale, implode(', ', $this->locales->all())));
        }

        return $locale;
    }

    /**
     * @template T of object
     *
     * @param \Closure(T): array<string, mixed> $serialize
     */
    private function paginated(Request $request, QueryBuilder $qb, \Closure $serialize): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $perPage = $request->query->getInt('per_page', self::DEFAULT_PER_PAGE);
        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new BadRequestHttpException(\sprintf('"page" must be at least 1 and "per_page" between 1 and %d.', self::MAX_PER_PAGE));
        }

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $perPage));

        /** @var list<T> $items */
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        $route = (string) $request->attributes->get('_route');
        $link = fn (int $number): string => $this->serializer->absolute($this->generateUrl($route, ['page' => $number] + $request->query->all()));

        return $this->json([
            'data' => array_map($serialize, $items),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages],
            'links' => [
                'self' => $link($page),
                'prev' => $page > 1 && $page <= $pages + 1 ? $link($page - 1) : null,
                'next' => $page < $pages ? $link($page + 1) : null,
            ],
        ]);
    }
}
