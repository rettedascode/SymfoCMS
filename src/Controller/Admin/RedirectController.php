<?php

namespace App\Controller\Admin;

use App\Entity\Redirect;
use App\Repository\RedirectRepository;
use App\Security\Voter\AdminAreaVoter;
use App\Service\RedirectManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/redirects', name: 'admin_redirect_')]
#[IsGranted(AdminAreaVoter::MANAGE_REDIRECTS)]
final class RedirectController extends AdminController
{
    public function __construct(
        private readonly RedirectRepository $redirects,
        private readonly RedirectManager $manager,
    ) {
    }

    /**
     * Lists all redirects; the form at the top adds one or, with "?edit=<id>", changes one.
     */
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $editing = $request->query->has('edit') ? $this->redirects->find($request->query->getInt('edit')) : null;
        $redirect = $editing ?? new Redirect();
        $source = $redirect->getSource();
        $target = $redirect->getTarget();
        $error = null;

        if ($request->isMethod('POST')) {
            $this->requireCsrfToken($request);
            $source = $request->request->getString('source');
            $target = $request->request->getString('target');

            $result = $this->manager->save($redirect, $source, $target);
            if (RedirectManager::OK === $result) {
                $this->addFlash('success', 'flash.saved');

                return $this->redirectToRoute('admin_redirect_index');
            }
            $error = $result;
        }

        return $this->render('admin/redirect/index.html.twig', [
            'redirects' => $this->redirects->findBy([], ['source' => 'ASC']),
            'editing' => $editing,
            'source' => $source,
            'target' => $target,
            'error' => $error,
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Redirect $redirect, EntityManagerInterface $entityManager): Response
    {
        $this->requireCsrfToken($request);

        $entityManager->remove($redirect);
        $entityManager->flush();
        $this->addFlash('success', 'flash.redirect_deleted');

        return $this->redirectToRoute('admin_redirect_index');
    }
}
