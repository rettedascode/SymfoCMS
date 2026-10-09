<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use App\Entity\PageTemplate;
use App\Repository\PageTemplateRepository;
use App\Security\Voter\AdminAreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admins save pages as templates and remove templates they no longer need.
 * Using a template when creating a page needs no special permission.
 */
#[IsGranted(AdminAreaVoter::MANAGE_TEMPLATES)]
final class PageTemplateController extends AdminController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/admin/page-templates', name: 'admin_page_template_index', methods: ['GET'])]
    public function index(PageTemplateRepository $templates): Response
    {
        return $this->render('admin/page_template/index.html.twig', [
            'templates' => $templates->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * Copies the saved blocks of a page into a new template.
     */
    #[Route('/admin/pages/{id<\d+>}/save-as-template', name: 'admin_page_template_create', methods: ['POST'])]
    public function create(Request $request, Page $page): Response
    {
        $this->requireCsrfToken($request);

        $name = mb_substr(trim($request->request->getString('template_name')), 0, 120);
        if ('' === $name) {
            $name = $page->getTitle();
        }

        $this->entityManager->persist(new PageTemplate($name, $page->getBlocks()));
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.template_saved');

        return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
    }

    #[Route('/admin/page-templates/{id<\d+>}/delete', name: 'admin_page_template_delete', methods: ['POST'])]
    public function delete(Request $request, PageTemplate $template): Response
    {
        $this->requireCsrfToken($request);

        // Pages created from the template are independent copies and stay as they are.
        $this->entityManager->remove($template);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.template_deleted');

        return $this->redirectToRoute('admin_page_template_index');
    }
}
