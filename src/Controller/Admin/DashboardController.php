<?php

namespace App\Controller\Admin;

use App\Dashboard\Dashboard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The start page of the admin area: widgets that every user arranges and resizes for themselves.
 */
final class DashboardController extends AdminController
{
    public function __construct(private readonly Dashboard $dashboard)
    {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->currentUser();
        $widgets = [];
        foreach ($this->dashboard->layout($user) as $widget) {
            $widgets[] = $widget + ['content' => $this->dashboard->content($widget['id'], $user)];
        }

        return $this->render('admin/dashboard/index.html.twig', [
            'widgets' => $widgets,
            'sizes' => Dashboard::SIZES,
        ]);
    }

    /**
     * The user dragged a widget to another place or changed its width:
     * {"widgets": [{"id": "drafts", "size": 3}, ...]} - all of them, in their new order.
     */
    #[Route('/admin/dashboard/layout', name: 'admin_dashboard_layout', methods: ['POST'])]
    public function arrange(Request $request): JsonResponse
    {
        $this->requireCsrfToken($request);

        try {
            $data = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
            $this->dashboard->arrange($this->currentUser(), \is_array($data) ? ($data['widgets'] ?? null) : null);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage());
        }

        return $this->json(['widgets' => $this->dashboard->layout($this->currentUser())]);
    }
}
