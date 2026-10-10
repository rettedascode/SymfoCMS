<?php

namespace App\Controller\Admin;

use App\Entity\Media;
use App\Form\ThemeSettingsType;
use App\Security\Voter\AdminAreaVoter;
use App\Theme\ThemeCustomizer;
use App\Theme\ThemeRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Design": choose the theme of the website and adjust its logo, colours and fonts.
 */
#[Route('/admin/design', name: 'admin_theme_')]
#[IsGranted(AdminAreaVoter::MANAGE_THEMES)]
final class ThemeController extends AdminController
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ThemeCustomizer $customizer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $active = $this->themes->active();
        $definitions = $this->customizer->definitions($active);
        $values = $this->customizer->values($active);

        $data = ['logo' => $this->customizer->logo()];
        foreach ($values['colors'] as $name => $value) {
            $data['color_'.$name] = $value;
        }
        foreach ($values['fonts'] as $name => $value) {
            $data['font_'.$name] = $value;
        }

        $form = $this->createForm(ThemeSettingsType::class, $data, $definitions);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $colors = $fonts = [];
            foreach (array_keys($definitions['colors']) as $name) {
                $colors[$name] = $form->get('color_'.$name)->getData();
            }
            foreach (array_keys($definitions['fonts']) as $name) {
                $fonts[$name] = $form->get('font_'.$name)->getData();
            }

            $logo = $form->get('logo')->getData();
            $this->customizer->setLogo($logo instanceof Media ? $logo : null);
            $this->customizer->save($active, $colors, $fonts);
            $this->addFlash('success', 'flash.saved');

            return $this->redirectToRoute('admin_theme_index');
        }

        $themes = [];
        foreach ($this->themes->all() as $theme) {
            $themes[] = ['theme' => $theme, 'colors' => $this->customizer->values($theme)['colors']];
        }

        return $this->render('admin/theme/index.html.twig', [
            'themes' => $themes,
            'active' => $active,
            'form' => $form,
            'has_settings' => [] !== $definitions['colors'] || [] !== $definitions['fonts'],
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/activate/{id<[a-z0-9-]+>}', name: 'activate', methods: ['POST'])]
    public function activate(Request $request, string $id): Response
    {
        $this->requireCsrfToken($request);
        $theme = $this->themes->get($id) ?? throw $this->createNotFoundException();

        $this->themes->activate($theme);
        $this->addFlash('success', 'flash.theme_activated');

        return $this->redirectToRoute('admin_theme_index');
    }

    /**
     * Back to the colours and fonts the theme comes with. The logo stays.
     */
    #[Route('/reset', name: 'reset', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        $this->requireCsrfToken($request);
        $this->customizer->reset($this->themes->active());
        $this->addFlash('success', 'flash.theme_reset');

        return $this->redirectToRoute('admin_theme_index');
    }
}
