<?php

namespace App\Controller\Admin;

use App\Security\Voter\PageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    /**
     * There is no dashboard on purpose: people land directly on the content they can edit.
     */
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_account');
    }
}
