<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use App\Security\Voter\AdminAreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/categories', name: 'admin_category_')]
#[IsGranted(AdminAreaVoter::MANAGE_CATEGORIES)]
final class CategoryController extends AdminController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Lists the categories and adds a new one with the form at the top of the list.
     */
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, CategoryRepository $categories): Response
    {
        $category = new Category();
        $form = $this->createForm(CategoryType::class, $category, ['with_slug' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($category);
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.category_created');

            return $this->redirectToRoute('admin_category_index');
        }

        return $this->render('admin/category/index.html.twig', [
            'form' => $form,
            'categories' => $categories->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Category $category): Response
    {
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.saved');

            return $this->redirectToRoute('admin_category_index');
        }

        return $this->render('admin/category/edit.html.twig', [
            'form' => $form,
            'category' => $category,
        ]);
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Category $category): Response
    {
        $this->requireCsrfToken($request);

        // Posts of this category are kept; the database sets their category to "none".
        $this->entityManager->remove($category);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.category_deleted');

        return $this->redirectToRoute('admin_category_index');
    }
}
