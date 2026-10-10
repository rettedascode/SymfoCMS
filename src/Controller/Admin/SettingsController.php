<?php

namespace App\Controller\Admin;

use App\Form\SettingsType;
use App\Security\Voter\AdminAreaVoter;
use App\Service\ContentLocales;
use App\Service\FormSubmitter;
use App\Service\Settings;
use App\Service\SiteInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAreaVoter::MANAGE_SETTINGS)]
final class SettingsController extends AdminController
{
    #[Route('/admin/settings', name: 'admin_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request, ContentLocales $locales, Settings $settings, SiteInfo $site): Response
    {
        $retention = $settings->get(FormSubmitter::RETENTION_SETTING);
        $form = $this->createForm(SettingsType::class, [
            'siteName' => $site->name(),
            'siteTagline' => $site->tagline(),
            'locales' => implode(', ', $locales->all()),
            'retentionDays' => \is_int($retention) ? $retention : null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $site->save((string) $form->get('siteName')->getData(), (string) $form->get('siteTagline')->getData());
            $locales->save(SettingsType::parseLocales((string) $form->get('locales')->getData()));
            $days = $form->get('retentionDays')->getData();
            $settings->set(FormSubmitter::RETENTION_SETTING, \is_int($days) ? $days : null);
            $this->addFlash('success', 'flash.saved');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings/edit.html.twig', ['form' => $form]);
    }
}
