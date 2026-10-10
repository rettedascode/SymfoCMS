<?php

namespace App\Controller\Admin;

use App\DragDrop\InvalidMoveException;
use App\DragDrop\MoveRequest;
use App\Entity\Category;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use App\Security\Voter\AdminAreaVoter;
use App\Service\ContentLocales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/categories', name: 'admin_category_')]
#[IsGranted(AdminAreaVoter::MANAGE_CATEGORIES)]
final class CategoryController extends AdminController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentLocales $locales,
    ) {
    }

    /**
     * Lists the categories and adds a new one with the form at the top of the list.
     */
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, CategoryRepository $categories): Response
    {
        $locale = $this->locales->fromRequest($request);
        $category = (new Category())->setLocale($locale);
        $form = $this->createForm(CategoryType::class, $category, ['with_slug' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // A new category goes to the end of the list.
            $category->setPosition(\count($categories->findOrdered($locale)));
            $this->entityManager->persist($category);
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.category_created');

            return $this->redirectToRoute('admin_category_index', $this->localeParameters($locale));
        }

        return $this->render('admin/category/index.html.twig', [
            'form' => $form,
            'categories' => $categories->findOrdered($locale),
            'locale' => $locale,
        ]);
    }

    /**
     * A category was dragged to another place in the list. The order is kept per language
     * and is the order of the categories on the blog.
     */
    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(Request $request, CategoryRepository $categories, TranslatorInterface $translator): JsonResponse
    {
        $this->requireCsrfToken($request);
        $move = MoveRequest::fromRequest($request);
        $category = $categories->find($move->id) ?? throw $this->createNotFoundException();

        // Categories are a plain list: there is nothing a category could be moved into.
        if (null !== $move->parent) {
            return $this->json(['error' => $translator->trans(InvalidMoveException::WRONG_PARENT)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ordered = array_values(array_filter($categories->findOrdered($category->getLocale()), static fn (Category $other): bool => $other !== $category));
        array_splice($ordered, min($move->position, \count($ordered)), 0, [$category]);
        foreach ($ordered as $position => $other) {
            $other->setPosition($position);
        }
        $this->entityManager->flush();

        return $this->json(['order' => array_map(static fn (Category $other): ?int => $other->getId(), $ordered)]);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Category $category): Response
    {
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.saved');

            return $this->redirectToRoute('admin_category_index', $this->localeParameters($category->getLocale()));
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

        return $this->redirectToRoute('admin_category_index', $this->localeParameters($category->getLocale()));
    }

    /**
     * Keeps the list on the language that was being edited.
     *
     * @return array<string, string>
     */
    private function localeParameters(string $locale): array
    {
        return $this->locales->isDefault($locale) ? [] : ['locale' => $locale];
    }
}
