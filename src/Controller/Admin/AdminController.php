<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Small helpers shared by the admin controllers.
 */
abstract class AdminController extends AbstractController
{
    /** Token id for hand-written admin forms and fetch() requests. */
    public const CSRF_ID = 'admin';

    protected function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * Accepts the token from a form field or, for fetch() requests, from the X-CSRF-Token header.
     */
    protected function requireCsrfToken(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');

        if (!$this->isCsrfTokenValid(self::CSRF_ID, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
