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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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

    /**
     * Turns the image by a quarter in the given direction.
     */
    #[Route('/{id<\d+>}/rotate', name: 'rotate', methods: ['POST'])]
    public function rotate(Request $request, Media $media): Response
    {
        return $this->change($request, $media, fn () => $this->mediaManager->transform($media, 'left' === $request->request->get('direction') ? 270 : 90, null));
    }

    /**
     * Keeps the chosen part of the image. The part is given as fractions (0-1) of width and height.
     */
    #[Route('/{id<\d+>}/crop', name: 'crop', methods: ['POST'])]
    public function crop(Request $request, Media $media): Response
    {
        $crop = [];
        foreach (['x', 'y', 'w', 'h'] as $key) {
            $value = $request->request->get($key);
            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
                throw new BadRequestHttpException('The crop area is not valid.');
            }
            $crop[$key] = (float) $value;
        }

        return $this->change($request, $media, fn () => $this->mediaManager->transform($media, 0, $crop));
    }

    #[Route('/{id<\d+>}/restore', name: 'restore', methods: ['POST'])]
    public function restore(Request $request, Media $media): Response
    {
        return $this->change($request, $media, fn () => $this->mediaManager->restore($media));
    }

    /**
     * Stores the focal point: the spot that stays visible wherever the image is cut to another shape.
     * Called by the image editor while the person clicks around, and by its form without JavaScript.
     */
    #[Route('/{id<\d+>}/focus', name: 'focus', methods: ['POST'])]
    public function focus(Request $request, Media $media): Response
    {
        $this->requireCsrfToken($request);
        $this->denyAccessUnlessGranted(MediaVoter::EDIT, $media);

        $isJson = 'json' === $request->getContentTypeFormat();
        $data = $isJson ? json_decode($request->getContent(), true) : $request->request->all();
        $x = \is_array($data) ? ($data['x'] ?? null) : null;
        $y = \is_array($data) ? ($data['y'] ?? null) : null;
        if (!is_numeric($x) || !is_numeric($y) || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
            throw new BadRequestHttpException('The focal point is not valid.');
        }

        $this->mediaManager->setFocalPoint($media, (float) $x, (float) $y);

        return $isJson
            ? $this->json(['x' => $media->getFocalX(), 'y' => $media->getFocalY()])
            : $this->redirectToRoute('admin_media_edit', ['id' => $media->getId()]);
    }

    /**
     * Shared frame of the editing actions: check token and permission, do the work, return to the image.
     *
     * @param \Closure(): void $work
     */
    private function change(Request $request, Media $media, \Closure $work): Response
    {
        $this->requireCsrfToken($request);
        $this->denyAccessUnlessGranted(MediaVoter::EDIT, $media);

        try {
            $work();
            $this->addFlash('success', 'flash.media_changed');
        } catch (InvalidUploadException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_media_edit', ['id' => $media->getId()]);
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
