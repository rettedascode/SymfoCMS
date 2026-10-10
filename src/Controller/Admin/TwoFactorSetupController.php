<?php

namespace App\Controller\Admin;

use App\Security\TwoFactor\TwoFactorManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lets every backend user switch two-step login on and off for their own account.
 */
#[Route('/admin/account/two-factor', name: 'admin_two_factor_')]
final class TwoFactorSetupController extends AdminController
{
    public function __construct(private readonly TwoFactorManager $twoFactor)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();

        if ($user->isTwoFactorEnabled()) {
            return $this->render('admin/account/two_factor.html.twig', ['backup_codes' => null, 'error' => null]);
        }

        return $this->setup($request, null);
    }

    /**
     * Second half of the setup: the first code from the app proves that scanning worked.
     */
    #[Route('/enable', name: 'enable', methods: ['POST'])]
    public function enable(Request $request): Response
    {
        $this->requireCsrfToken($request);
        $user = $this->currentUser();
        if ($user->isTwoFactorEnabled()) {
            return $this->redirectToRoute('admin_two_factor_index');
        }

        $codes = $this->twoFactor->enable($user, $request->request->getString('code'), $request->getSession());
        if (null === $codes) {
            return $this->setup($request, 'two_factor.error.wrong_code');
        }

        // The backup codes are shown now and never again.
        return $this->render('admin/account/two_factor.html.twig', ['backup_codes' => $codes, 'error' => null]);
    }

    #[Route('/backup-codes', name: 'backup_codes', methods: ['POST'])]
    public function backupCodes(Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        $this->requireCsrfToken($request);
        $user = $this->currentUser();
        if (!$user->isTwoFactorEnabled()) {
            return $this->redirectToRoute('admin_two_factor_index');
        }
        if (!$passwordHasher->isPasswordValid($user, $request->request->getString('password'))) {
            return $this->render('admin/account/two_factor.html.twig', ['backup_codes' => null, 'error' => 'two_factor.error.wrong_password'], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin/account/two_factor.html.twig', ['backup_codes' => $this->twoFactor->regenerateBackupCodes($user), 'error' => null]);
    }

    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        $this->requireCsrfToken($request);
        $user = $this->currentUser();
        if (!$user->isTwoFactorEnabled()) {
            return $this->redirectToRoute('admin_two_factor_index');
        }

        // Switching protection off needs the password again, in case somebody else sits at the open browser.
        if (!$passwordHasher->isPasswordValid($user, $request->request->getString('password'))) {
            return $this->render('admin/account/two_factor.html.twig', ['backup_codes' => null, 'error' => 'two_factor.error.wrong_password'], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->twoFactor->disable($user);
        $this->addFlash('success', 'flash.two_factor_disabled');

        return $this->redirectToRoute($this->twoFactor->mustSetUp($user) ? 'admin_two_factor_index' : 'admin_account');
    }

    private function setup(Request $request, ?string $error): Response
    {
        $user = $this->currentUser();
        $secret = $this->twoFactor->pendingSecret($request->getSession());

        return $this->render('admin/account/two_factor_setup.html.twig', [
            'secret' => $secret,
            'qr_code' => $this->twoFactor->qrCode($user, $secret),
            'required' => $this->twoFactor->mustSetUp($user),
            'error' => $error,
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
