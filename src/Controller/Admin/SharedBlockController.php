<?php

namespace App\Controller\Admin;

use App\Block\BlockNormalizer;
use App\Entity\SharedBlock;
use App\Form\SharedBlockType;
use App\Repository\SharedBlockRepository;
use App\Security\Voter\AdminAreaVoter;
use App\Service\ContentEditor;
use App\Service\SharedBlockUsage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/shared-blocks', name: 'admin_shared_block_')]
#[IsGranted(AdminAreaVoter::MANAGE_SHARED_BLOCKS)]
final class SharedBlockController extends AdminController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedBlockRepository $sharedBlocks,
        private readonly SharedBlockUsage $usage,
        private readonly ContentEditor $editor,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $blocks = $this->sharedBlocks->findBy([], ['name' => 'ASC']);

        return $this->render('admin/shared_block/index.html.twig', [
            'shared_blocks' => $blocks,
            'usage' => array_combine(
                array_map(static fn (SharedBlock $block): int => (int) $block->getId(), $blocks),
                array_map($this->usage->count(...), $blocks),
            ),
        ]);
    }

    /**
     * Called by the editor ("Save as shared block"): stores one block under a name.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, BlockNormalizer $normalizer, TranslatorInterface $translator): JsonResponse
    {
        $this->requireCsrfToken($request);

        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $name = \is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        // A shared block cannot contain other shared blocks, which rules out endless loops.
        $blocks = $normalizer->normalize([$payload['block'] ?? null], allowShared: false);

        if ('' === $name || mb_strlen($name) > 120 || [] === $blocks) {
            return $this->json(['error' => $translator->trans('shared_block.invalid', [], 'validators')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sharedBlock = (new SharedBlock())->setName($name)->setBlocks($blocks);
        $this->entityManager->persist($sharedBlock);
        $this->entityManager->flush();

        return $this->json($this->editor->describeSharedBlock($sharedBlock), Response::HTTP_CREATED);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new SharedBlock());
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SharedBlock $sharedBlock): Response
    {
        return $this->form($request, $sharedBlock);
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, SharedBlock $sharedBlock): Response
    {
        $this->requireCsrfToken($request);

        // Pages that still reference the block simply stop showing it.
        $this->entityManager->remove($sharedBlock);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.shared_block_deleted');

        return $this->redirectToRoute('admin_shared_block_index');
    }

    private function form(Request $request, SharedBlock $sharedBlock): Response
    {
        $isNew = null === $sharedBlock->getId();
        $form = $this->createForm(SharedBlockType::class, $sharedBlock);
        $form->handleRequest($request);
        $blocks = $sharedBlock->getBlocks();
        $errors = [];

        if ($form->isSubmitted()) {
            [$blocks, $errors] = $this->editor->submittedBlocks($form, allowShared: false);

            if ($form->isValid()) {
                $sharedBlock->setBlocks($blocks);
                $this->entityManager->persist($sharedBlock);
                $this->entityManager->flush();
                $this->addFlash('success', 'flash.shared_block_saved');

                return $this->redirectToRoute('admin_shared_block_edit', ['id' => $sharedBlock->getId()]);
            }
        }

        return $this->render('admin/shared_block/edit.html.twig', [
            'form' => $form,
            'shared_block' => $sharedBlock,
            'used_by' => $isNew ? [] : $this->usage->find($sharedBlock),
            'editor_config' => $this->editor->fragmentConfig($blocks, $errors),
        ]);
    }
}
