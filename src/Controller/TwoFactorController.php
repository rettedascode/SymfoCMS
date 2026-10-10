<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\TwoFactor\TwoFactorManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class TwoFactorController extends AbstractController
{
    /**
     * The second step of the login: a code from the authenticator app or a backup code.
     * App\EventSubscriber\TwoFactorSubscriber sends users here until they pass it.
     */
    #[Route('/login/two-factor', name: 'app_two_factor', methods: ['GET', 'POST'])]
    public function challenge(
        Request $request,
        TwoFactorManager $twoFactor,
        #[Autowire(service: 'limiter.two_factor')]
        RateLimiterFactory $limiter,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        if (!$user->isTwoFactorEnabled() || $twoFactor->isVerified($user, $request->getSession())) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            // Six digits can be guessed; a handful of tries per quarter of an hour cannot.
            $limit = $limiter->create('user-'.$user->getId());

            if (!$this->isCsrfTokenValid('authenticate', $request->request->getString('_csrf_token'))) {
                $error = 'two_factor.error.expired';
            } elseif (!$limit->consume()->isAccepted()) {
                $error = 'two_factor.error.too_many';
            } elseif ($twoFactor->verify($user, $request->request->getString('code'))) {
                $limit->reset();
                $twoFactor->markVerified($user, $request->getSession());

                return $this->redirectToRoute('admin_dashboard');
            } else {
                $error = 'two_factor.error.wrong_code';
            }
        }

        return $this->render('security/two_factor.html.twig', ['error' => $error], new Response(
            status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        ));
    }
}
