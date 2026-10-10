<?php

namespace App\Controller\Admin;

use App\EventSubscriber\ApiSubscriber;
use App\Form\SettingsType;
use App\Security\TwoFactor\TwoFactorManager;
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
    public function edit(Request $request, ContentLocales $locales, Settings $settings, SiteInfo $site, TwoFactorManager $twoFactor): Response
    {
        $retention = $settings->get(FormSubmitter::RETENTION_SETTING);
        $form = $this->createForm(SettingsType::class, [
            'siteName' => $site->name(),
            'siteTagline' => $site->tagline(),
            'locales' => implode(', ', $locales->all()),
            'retentionDays' => \is_int($retention) ? $retention : null,
            'twoFactorAdmins' => $twoFactor->isRequiredForAdmins(),
            'apiEnabled' => true === $settings->get(ApiSubscriber::ENABLED_SETTING),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $site->save((string) $form->get('siteName')->getData(), (string) $form->get('siteTagline')->getData());
            $locales->save(SettingsType::parseLocales((string) $form->get('locales')->getData()));
            $days = $form->get('retentionDays')->getData();
            $settings->set(FormSubmitter::RETENTION_SETTING, \is_int($days) ? $days : null);
            $twoFactor->requireForAdmins(true === $form->get('twoFactorAdmins')->getData());
            $settings->set(ApiSubscriber::ENABLED_SETTING, true === $form->get('apiEnabled')->getData() ? true : null);
            $this->addFlash('success', 'flash.saved');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings/edit.html.twig', [
            'form' => $form,
            'api_enabled' => true === $settings->get(ApiSubscriber::ENABLED_SETTING),
        ]);
    }
}
