<?php

namespace App\Controller\Admin;

use App\Form\AccountType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AdminController
{
    /**
     * Lets every backend user change their own name, language and password.
     */
    #[Route('/admin/account', name: 'admin_account', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = $this->currentUser();
        $form = $this->createForm(AccountType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('newPassword')->getData();
            if (\is_string($newPassword) && '' !== $newPassword) {
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            }

            $entityManager->flush();
            $this->addFlash('success', 'flash.saved');

            return $this->redirectToRoute('admin_account');
        }

        return $this->render('admin/account/edit.html.twig', ['form' => $form]);
    }
}
