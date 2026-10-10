<?php

namespace App\Controller;

use App\Form\InstallDatabaseType;
use App\Form\InstallSiteType;
use App\Install\DatabaseSetup;
use App\Install\Installer;
use App\Install\InstallState;
use App\Install\Requirements;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The setup wizard shown on the first visit of a new website:
 * requirements, database, then the website and its administrator.
 *
 * Once the website has a user, every address of the wizard answers "not found".
 */
#[Route('/install', name: 'install_')]
final class InstallController extends AbstractController
{
    public function __construct(
        private readonly InstallState $state,
        private readonly Requirements $requirements,
        private readonly DatabaseSetup $database,
    ) {
    }

    #[Route('', name: 'requirements', methods: ['GET'])]
    public function requirements(): Response
    {
        $this->denyWhenInstalled();

        return $this->render('install/requirements.html.twig', [
            'checks' => $this->requirements->check(),
            'satisfied' => $this->requirements->isSatisfied(),
        ]);
    }

    #[Route('/database', name: 'database', methods: ['GET', 'POST'])]
    public function database(Request $request): Response
    {
        $this->denyWhenInstalled();
        if (!$this->requirements->isSatisfied()) {
            return $this->redirectToRoute('install_requirements');
        }

        // A connection set by the server is used as it is.
        if ($this->database->isManagedByServer()) {
            return $this->render('install/database_managed.html.twig', [
                'database' => $this->database->describeCurrent(),
                'error' => $this->database->testCurrent(),
            ]);
        }

        $form = $this->createForm(InstallDatabaseType::class, ['driver' => DatabaseSetup::SQLITE, 'host' => 'localhost'], [
            'drivers' => $this->requirements->availableDrivers(),
        ]);
        $form->handleRequest($request);
        $manual = null;

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{driver: string, host?: string|null, port?: int|null, name?: string|null, user?: string|null, password?: string|null} $data */
            $data = $form->getData();
            $url = $this->database->url($data);
            $error = $this->database->test($url);

            if (null !== $error) {
                $form->addError(new FormError($error));
            } elseif ($this->database->canWriteConfiguration()) {
                $this->database->write($url);

                return $this->redirectToRoute('install_site');
            } else {
                // The connection works but cannot be stored: show what to add to the file by hand.
                $manual = $this->database->configurationFor($url);
            }
        }

        return $this->render('install/database.html.twig', ['form' => $form, 'manual' => $manual], new Response(
            status: $form->isSubmitted() && null === $manual ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        ));
    }

    #[Route('/site', name: 'site', methods: ['GET', 'POST'])]
    public function site(Request $request, Installer $installer, Security $security): Response
    {
        $this->denyWhenInstalled();
        if (!$this->requirements->isSatisfied()) {
            return $this->redirectToRoute('install_requirements');
        }
        if (null !== $this->database->testCurrent()) {
            return $this->redirectToRoute('install_database');
        }

        $form = $this->createForm(InstallSiteType::class, ['locale' => $request->getLocale()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{siteName: string, locale: string, adminName: string, email: string, password: string} $data */
            $data = $form->getData();

            try {
                $admin = $installer->install($data['siteName'], $data['locale'], $data['adminName'], $data['email'], $data['password']);
            } catch (\Throwable $exception) {
                $form->addError(new FormError($exception->getMessage()));
                $admin = null;
            }

            if (null !== $admin) {
                // The new administrator is logged in right away and lands in the admin area.
                $security->login($admin, 'form_login', 'main');
                $this->addFlash('success', 'install.done');

                return $this->redirectToRoute('admin_dashboard');
            }
        }

        return $this->render('install/site.html.twig', ['form' => $form], new Response(
            status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        ));
    }

    private function denyWhenInstalled(): void
    {
        if ($this->state->isInstalled()) {
            throw $this->createNotFoundException();
        }
    }
}
