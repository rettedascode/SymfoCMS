<?php

namespace App\Controller\Admin;

use App\Form\SettingsType;
use App\Security\Voter\AdminAreaVoter;
use App\Service\ContentLocales;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAreaVoter::MANAGE_SETTINGS)]
final class SettingsController extends AdminController
{
    #[Route('/admin/settings', name: 'admin_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request, ContentLocales $locales): Response
    {
        $form = $this->createForm(SettingsType::class, ['locales' => implode(', ', $locales->all())]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $locales->save(SettingsType::parseLocales((string) $form->get('locales')->getData()));
            $this->addFlash('success', 'flash.saved');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings/edit.html.twig', ['form' => $form]);
    }
}
