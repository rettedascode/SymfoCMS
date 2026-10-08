<?php

namespace App\Controller\Admin;

use App\Entity\Media;
use App\Exception\InvalidUploadException;
use App\Form\MediaType;
use App\Repository\MediaRepository;
use App\Security\Voter\MediaVoter;
use App\Service\ContentEditor;
use App\Service\MediaManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/media', name: 'admin_media_')]
#[IsGranted(MediaVoter::BROWSE)]
final class MediaController extends AdminController
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly MediaManager $mediaManager,
        private readonly ContentEditor $editor,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->mediaRepository->count([]);

        return $this->render('admin/media/index.html.twig', [
            'media' => $this->mediaRepository->findPage($page, self::PER_PAGE),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'i18n' => $this->editor->translations('editor.'),
            'accept' => implode(',', array_keys(MediaManager::MIME_TYPES)),
            'max_upload_mb' => $this->mediaManager->maxUploadMb(),
        ]);
    }

    /**
     * One page of the library as JSON, for the image picker dialog.
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // "?id=5" looks up a single image, e.g. to show the thumbnail of a restored form value.
        if ($request->query->has('id')) {
            $media = $this->mediaRepository->find($request->query->getInt('id'));

            return $this->json(['items' => null === $media ? [] : [$this->editor->describeMedia($media)], 'hasMore' => false]);
        }

        $page = max(1, $request->query->getInt('page', 1));
        // Fetch one extra item to know whether there is a next page.
        $items = $this->mediaRepository->findPage($page, self::PER_PAGE + 1);
        $hasMore = \count($items) > self::PER_PAGE;

        return $this->json([
            'items' => array_map($this->editor->describeMedia(...), \array_slice($items, 0, self::PER_PAGE)),
            'hasMore' => $hasMore,
        ]);
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    #[IsGranted(MediaVoter::UPLOAD)]
    public function upload(Request $request, TranslatorInterface $translator): JsonResponse
    {
        $this->requireCsrfToken($request);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => $translator->trans('media.upload_failed', [], 'validators')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $media = $this->mediaManager->upload($file, $this->currentUser());
        } catch (InvalidUploadException $exception) {
            return $this->json(
                ['error' => $translator->trans($exception->getMessage(), $exception->getParameters(), 'validators')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(['media' => $this->editor->describeMedia($media)], Response::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Media $media, EntityManagerInterface $entityManager): Response
    {
        $canEdit = $this->isGranted(MediaVoter::EDIT, $media);
        $form = $this->createForm(MediaType::class, $media, ['disabled' => !$canEdit]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyAccessUnlessGranted(MediaVoter::EDIT, $media);
            $entityManager->flush();
            $this->addFlash('success', 'flash.saved');

            return $this->redirectToRoute('admin_media_index');
        }

        return $this->render('admin/media/edit.html.twig', [
            'form' => $form,
            'media' => $media,
            'can_edit' => $canEdit,
        ]);
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Media $media): Response
    {
        $this->requireCsrfToken($request);
        $this->denyAccessUnlessGranted(MediaVoter::DELETE, $media);

        $this->mediaManager->delete($media);
        $this->addFlash('success', 'flash.media_deleted');

        return $this->redirectToRoute('admin_media_index');
    }
}
