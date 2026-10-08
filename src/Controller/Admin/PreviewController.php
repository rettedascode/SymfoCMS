<?php

namespace App\Controller\Admin;

use App\Block\BlockNormalizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PreviewController extends AdminController
{
    /**
     * Renders unsaved editor content with the public theme for the live preview.
     */
    #[Route('/admin/preview', name: 'admin_preview', methods: ['POST'])]
    public function __invoke(Request $request, BlockNormalizer $normalizer): Response
    {
        $this->requireCsrfToken($request);

        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $response = $this->render('site/preview.html.twig', [
            'title' => \is_string($payload['title'] ?? null) ? $payload['title'] : '',
            'blocks' => $normalizer->normalize($payload['blocks'] ?? []),
            'kind' => 'post' === ($payload['kind'] ?? null) ? 'post' : 'page',
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
