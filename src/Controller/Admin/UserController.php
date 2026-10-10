<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\TwoFactor\TwoFactorManager;
use App\Security\Voter\UserVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin_user_')]
#[IsGranted(UserVoter::MANAGE)]
final class UserController extends AdminController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $users->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = (new User())->setLocale($this->currentUser()->getLocale());

        return $this->form($request, $user);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        return $this->form($request, $user);
    }

    #[Route('/{id<\d+>}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request, User $user): Response
    {
        $this->requireCsrfToken($request);
        $this->denyAccessUnlessGranted(UserVoter::CHANGE_ACCESS, $user);

        $user->setActive(!$user->isActive());
        $this->entityManager->flush();
        $this->addFlash('success', $user->isActive() ? 'flash.user_activated' : 'flash.user_deactivated');

        return $this->redirectToRoute('admin_user_index');
    }

    /**
     * For people who lost their phone and their backup codes: switches two-step login off so they can log in and set it up again.
     */
    #[Route('/{id<\d+>}/reset-two-factor', name: 'reset_two_factor', methods: ['POST'])]
    public function resetTwoFactor(Request $request, User $user, TwoFactorManager $twoFactor): Response
    {
        $this->requireCsrfToken($request);
        $this->denyAccessUnlessGranted(UserVoter::CHANGE_ACCESS, $user);

        $twoFactor->disable($user);
        $this->addFlash('success', 'flash.two_factor_reset');

        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, User $user): Response
    {
        $this->requireCsrfToken($request);
        $this->denyAccessUnlessGranted(UserVoter::DELETE, $user);

        $this->entityManager->remove($user);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.user_deleted');

        return $this->redirectToRoute('admin_user_index');
    }

    private function form(Request $request, User $user): Response
    {
        $isNew = null === $user->getId();
        $form = $this->createForm(UserType::class, $user, [
            'require_password' => $isNew,
            'can_change_access' => $this->isGranted(UserVoter::CHANGE_ACCESS, $user),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if (\is_string($plainPassword) && '' !== $plainPassword) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->addFlash('success', $isNew ? 'flash.user_created' : 'flash.saved');

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }
}
