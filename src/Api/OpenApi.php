<?php

namespace App\Api;

/**
 * The OpenAPI 3.1 description of the read API, served at /api/v1/openapi.json.
 * It documents what App\Controller\Api\ApiController and App\Api\ApiSerializer do.
 */
final class OpenApi
{
    public const VERSION = '1.0.0';

    /**
     * @return array<string, mixed>
     */
    public function describe(string $baseUrl): array
    {
        $ref = static fn (string $schema): array => ['$ref' => '#/components/schemas/'.$schema];
        $nullable = static fn (array $schema): array => ['oneOf' => [$schema, ['type' => 'null']]];

        $locale = ['name' => 'locale', 'in' => 'query', 'description' => 'Only content of this language, e.g. "de". Without it, all languages are returned.', 'schema' => ['type' => 'string']];
        $slug = ['name' => 'slug', 'in' => 'query', 'description' => 'Only the entry with this address part.', 'schema' => ['type' => 'string']];
        $paging = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]],
        ];
        $id = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];

        $error = ['description' => 'Error', 'content' => ['application/json' => ['schema' => $ref('Error')]]];
        $list = static fn (string $summary, string $schema, array $parameters): array => ['get' => [
            'summary' => $summary,
            'parameters' => [...$parameters, ...$paging],
            'responses' => [
                '200' => ['description' => 'A page of results', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['data', 'meta', 'links'],
                    'properties' => ['data' => ['type' => 'array', 'items' => $ref($schema)], 'meta' => $ref('Pagination'), 'links' => $ref('PageLinks')],
                ]]]],
                '400' => $error,
            ],
        ]];
        $item = static fn (string $summary, string $schema): array => ['get' => [
            'summary' => $summary,
            'parameters' => [$id],
            'responses' => [
                '200' => ['description' => 'The entry', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => ['data' => $ref($schema)],
                ]]]],
                '404' => $error,
            ],
        ]];

        $string = ['type' => 'string'];
        $integer = ['type' => 'integer'];
        $dateTime = ['type' => 'string', 'format' => 'date-time'];
        $url = ['type' => 'string', 'format' => 'uri'];

        $content = [
            'id' => $integer,
            'type' => ['type' => 'string', 'enum' => ['page', 'post']],
            'locale' => $string,
            'title' => $string,
            'slug' => $string,
            'url' => $url,
            'seo_title' => $nullable($string),
            'meta_description' => $nullable($string),
            'share_image' => $nullable($ref('Media')),
            'created_at' => $dateTime,
            'updated_at' => $dateTime,
            'translations' => ['type' => 'array', 'description' => 'The published versions in other languages.', 'items' => ['type' => 'object', 'properties' => ['locale' => $string, 'id' => $integer, 'url' => $url]]],
        ];
        $body = [
            'blocks' => ['type' => 'array', 'description' => 'The content as structured data, in the order it is shown.', 'items' => $ref('Block')],
            'html' => ['type' => 'string', 'description' => 'The same content rendered as HTML. Addresses of images and links inside it start at the root of the website.'],
        ];
        $pageFields = [
            'homepage' => ['type' => 'boolean'],
            'parent' => $nullable(['type' => 'integer', 'description' => 'Id of the parent page; null for pages on the top level.']),
            'path' => ['type' => 'string', 'description' => 'The slugs of the parent pages and of the page itself, e.g. "about/team".'],
        ];
        $post = [
            'excerpt' => $nullable($string),
            'published_at' => $nullable($dateTime),
            'author' => $nullable(['type' => 'object', 'properties' => ['name' => $string]]),
            'category' => $nullable($ref('Category')),
            'cover_image' => $nullable($ref('Media')),
        ];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'SymfoCMS read API',
                'version' => self::VERSION,
                'description' => 'Read-only access to everything that is public on the website: published pages and posts, categories, menus and images. No authentication is needed. Drafts and scheduled posts are never included.',
            ],
            'servers' => [['url' => $baseUrl.'/api/v1']],
            'paths' => [
                '/' => ['get' => ['summary' => 'Entry point with the languages of the website and links to all lists', 'responses' => ['200' => ['description' => 'Overview']]]],
                '/pages' => $list('Published pages, ordered by title', 'Page', [$locale, $slug]),
                '/pages/{id}' => $item('One published page with its content', 'PageDetail'),
                '/posts' => $list('Published blog posts, newest first', 'Post', [$locale, $slug, ['name' => 'category', 'in' => 'query', 'description' => 'Only posts of the category with this slug.', 'schema' => $string]]),
                '/posts/{id}' => $item('One published blog post with its content', 'PostDetail'),
                '/categories' => $list('Blog categories, ordered by name', 'Category', [$locale, $slug]),
                '/menus' => ['get' => [
                    'summary' => 'The main and the footer menu of one language',
                    'parameters' => [['name' => 'locale', 'in' => 'query', 'description' => 'Language of the menus. Defaults to the default language of the website.', 'schema' => $string]],
                    'responses' => [
                        '200' => ['description' => 'Both menus', 'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => ['type' => 'object', 'properties' => ['main' => ['type' => 'array', 'items' => $ref('MenuItem')], 'footer' => ['type' => 'array', 'items' => $ref('MenuItem')]]],
                                'meta' => ['type' => 'object', 'properties' => ['locale' => $string]],
                            ],
                        ]]]],
                        '400' => $error,
                    ],
                ]],
                '/media' => $list('Images of the media library, newest first', 'Media', []),
                '/media/{id}' => $item('One image', 'Media'),
            ],
            'components' => ['schemas' => [
                'Page' => ['type' => 'object', 'properties' => $content + $pageFields],
                'PageDetail' => ['type' => 'object', 'properties' => $content + $pageFields + $body],
                'Post' => ['type' => 'object', 'properties' => $content + $post],
                'PostDetail' => ['type' => 'object', 'properties' => $content + $post + $body],
                'Block' => [
                    'type' => 'object',
                    'required' => ['type', 'data'],
                    'properties' => [
                        'type' => ['type' => 'string', 'description' => 'The block type, e.g. "heading", "text", "image", "columns".'],
                        'data' => ['type' => 'object', 'description' => 'The fields of the block. Images are referenced by the id of a media entry.', 'additionalProperties' => true],
                    ],
                ],
                'Category' => ['type' => 'object', 'properties' => ['id' => $integer, 'locale' => $string, 'name' => $string, 'slug' => $string, 'url' => $url]],
                'Media' => ['type' => 'object', 'properties' => [
                    'id' => $integer,
                    'url' => $url,
                    'alt' => $string,
                    'mime_type' => $string,
                    'width' => $integer,
                    'height' => $integer,
                    'size' => ['type' => 'integer', 'description' => 'File size in bytes.'],
                    'focal_point' => ['type' => 'object', 'description' => 'The most important spot of the image, from 0 (left/top) to 1 (right/bottom).', 'properties' => ['x' => ['type' => 'number'], 'y' => ['type' => 'number']]],
                    'variants' => ['type' => 'object', 'description' => 'Other versions, keyed by name: smaller copies (thumb, medium, large) and "social", cut to 1200 x 630 around the focal point.', 'additionalProperties' => ['type' => 'object', 'properties' => ['url' => $url, 'width' => $integer, 'height' => $integer]]],
                ]],
                'MenuItem' => ['type' => 'object', 'properties' => ['label' => $string, 'url' => $string, 'children' => ['type' => 'array', 'items' => $ref('MenuItem')]]],
                'Pagination' => ['type' => 'object', 'properties' => ['page' => $integer, 'per_page' => $integer, 'total' => $integer, 'pages' => $integer]],
                'PageLinks' => ['type' => 'object', 'properties' => ['self' => $url, 'prev' => $nullable($url), 'next' => $nullable($url)]],
                'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => ['status' => $integer, 'message' => $string]]]],
            ]],
        ];
    }
}
