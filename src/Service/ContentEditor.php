<?php

namespace App\Service;

use App\Block\BlockNormalizer;
use App\Block\BlockRegistry;
use App\Block\BlockValidator;
use App\Block\Field;
use App\Controller\Admin\AdminController;
use App\Entity\Content;
use App\Entity\Form;
use App\Entity\Media;
use App\Entity\SharedBlock;
use App\Repository\FormRepository;
use App\Repository\MediaRepository;
use App\Repository\SharedBlockRepository;
use App\Security\Voter\AdminAreaVoter;
use App\Twig\CmsExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything the page and post controllers share around the block editor:
 * the configuration sent to the browser and the autosave.
 */
final class ContentEditor
{
    private const MAX_AUTOSAVE_FIELDS = 40;
    private const MAX_AUTOSAVE_FIELD_LENGTH = 5000;

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly BlockNormalizer $normalizer,
        private readonly BlockValidator $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaRepository $mediaRepository,
        private readonly CmsExtension $cms,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly SharedBlockRepository $sharedBlocks,
        private readonly FormRepository $forms,
        private readonly Security $security,
        private readonly MediaManager $mediaManager,
    ) {
    }

    /**
     * What every use of the editor needs: block types, texts, tokens and the media of the given blocks.
     *
     * @param array<array-key, mixed> $blocksWithMedia
     * @param array<string, string>   $errors
     *
     * @return array{types: list<array<string, mixed>>, urls: array<string, string|null>, ...<string, mixed>}
     */
    private function baseConfig(array $blocksWithMedia, array $errors): array
    {
        return [
            'forms' => array_map(static fn (Form $form): array => ['id' => $form->getId(), 'name' => $form->getName()], $this->forms->findBy([], ['name' => 'ASC'])),
            'formsUrl' => $this->security->isGranted(AdminAreaVoter::MANAGE_FORMS) ? $this->urlGenerator->generate('admin_form_index') : null,
            'errors' => $errors,
            'types' => $this->typeDefinitions(),
            'media' => $this->mediaMap($blocksWithMedia),
            'i18n' => $this->translations('editor.'),
            'csrf' => $this->csrfTokenManager->getToken(AdminController::CSRF_ID)->getValue(),
            'locale' => $this->translator->getLocale(),
            // What may be dropped into the editor from the desktop; the upload itself checks again.
            'upload' => [
                'maxBytes' => $this->mediaManager->maxUploadMb() * 1024 * 1024,
                'types' => array_keys(MediaManager::MIME_TYPES),
            ],
            'urls' => [
                'preview' => $this->urlGenerator->generate('admin_preview'),
                'mediaList' => $this->urlGenerator->generate('admin_media_list'),
                'mediaUpload' => $this->urlGenerator->generate('admin_media_upload'),
            ],
        ];
    }

    /**
     * Configuration for the "editor" Stimulus controller.
     *
     * @param list<array{type: string, data: array<string, mixed>}> $blocks blocks to show (the saved ones, or the submitted ones after a validation error)
     * @param array{autosave: string, discard: string}              $urls
     * @param array<string, string>                                 $errors problems found by BlockValidator, keyed by "<block path>:<field>"
     *
     * @return array<string, mixed>
     */
    public function config(Content $content, array $blocks, array $urls, string $kind, array $errors = []): array
    {
        $autosave = $content->getAutosave();
        $canShare = $this->security->isGranted(AdminAreaVoter::MANAGE_SHARED_BLOCKS);

        $config = $this->baseConfig([...$blocks, ...($autosave['blocks'] ?? [])], $errors);
        $config['kind'] = $kind;
        $config['blocks'] = $blocks;
        $config['autosave'] = $autosave;
        $config['urls'] += $urls;
        // Turning a block into a shared block is for people who may manage shared blocks.
        $config['urls']['sharedCreate'] = $canShare ? $this->urlGenerator->generate('admin_shared_block_create') : null;
        $config['sharedBlocks'] = array_map($this->describeSharedBlock(...), $this->sharedBlocks->findBy([], ['name' => 'ASC']));

        return $config;
    }

    /**
     * Editor configuration for the content of a shared block itself: no
     * autosave, and shared blocks cannot be put into shared blocks.
     *
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     * @param array<string, string>                                 $errors
     *
     * @return array<string, mixed>
     */
    public function fragmentConfig(array $blocks, array $errors = []): array
    {
        $config = $this->baseConfig($blocks, $errors);
        $config['kind'] = 'fragment';
        $config['blocks'] = $blocks;
        $config['autosave'] = null;
        $config['sharedBlocks'] = [];
        $config['types'] = array_values(array_filter($config['types'], static fn (array $type): bool => !$type['reference']));

        return $config;
    }

    /**
     * @return array{id: int|null, name: string, editUrl: string}
     */
    public function describeSharedBlock(SharedBlock $sharedBlock): array
    {
        return [
            'id' => $sharedBlock->getId(),
            'name' => $sharedBlock->getName(),
            'editUrl' => $this->urlGenerator->generate('admin_shared_block_edit', ['id' => $sharedBlock->getId()]),
        ];
    }

    /**
     * Reads the blocks sent by the editor form, cleans them up and checks them.
     * Problems are added to the form (which makes it invalid) and returned for the editor.
     *
     * @param FormInterface<mixed> $form a submitted form with the unmapped "blocks" field
     *
     * @return array{list<array{type: string, data: array<string, mixed>}>, array<string, string>} blocks and errors
     */
    public function submittedBlocks(FormInterface $form, bool $allowShared = true): array
    {
        $json = $form->get('blocks')->getData();
        $blocks = $this->normalizer->normalizeJson(\is_string($json) ? $json : null, $allowShared);
        $errors = $this->validator->validate($blocks);

        if ([] !== $errors) {
            $form->addError(new FormError($this->translator->trans('content.blocks_invalid', [], 'validators')));
        }

        return [$blocks, $errors];
    }

    /**
     * Stores the current editor state next to the content without touching what is published.
     */
    public function autosave(Content $content, Request $request): \DateTimeImmutable
    {
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $fields = [];
        foreach (\is_array($payload['fields'] ?? null) ? $payload['fields'] : [] as $name => $value) {
            if (\count($fields) >= self::MAX_AUTOSAVE_FIELDS) {
                break;
            }
            if (\is_string($name) && \is_scalar($value) && !str_contains($name, '_token')) {
                $fields[mb_substr($name, 0, 100)] = mb_substr((string) $value, 0, self::MAX_AUTOSAVE_FIELD_LENGTH);
            }
        }

        $savedAt = new \DateTimeImmutable();
        $this->writeAutosave($content, [
            'fields' => $fields,
            'blocks' => $this->normalizer->normalize($payload['blocks'] ?? []),
            'savedAt' => $savedAt->format(\DATE_ATOM),
        ]);

        return $savedAt;
    }

    public function discardAutosave(Content $content): void
    {
        $this->writeAutosave($content, null);
    }

    /**
     * Block type definitions with translated labels, as consumed by the editor.
     *
     * @return list<array<string, mixed>>
     */
    public function typeDefinitions(): array
    {
        $definitions = [];
        foreach ($this->registry->all() as $name => $type) {
            $fields = array_map($this->describeField(...), $type->getFields());

            $definitions[] = [
                'type' => $name,
                'label' => $this->translator->trans($type->getLabel()),
                'icon' => $type->getIcon(),
                'layout' => $this->registry->isLayout($type),
                'reference' => $this->registry->isReference($type),
                'fields' => $fields,
            ];
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeField(Field $field): array
    {
        $options = [];
        foreach ($field->options as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $this->translator->trans($label)];
        }

        return [
            'name' => $field->name,
            'type' => $field->type,
            'label' => $this->translator->trans($field->label),
            'help' => null !== $field->help ? $this->translator->trans($field->help) : null,
            'placeholder' => null !== $field->placeholder ? $this->translator->trans($field->placeholder) : null,
            'default' => $field->default,
            'required' => $field->required,
            'options' => $options,
            'fields' => array_map($this->describeField(...), $field->fields),
        ];
    }

    /**
     * All messages of the current locale whose key starts with the given prefix (falling back to English).
     *
     * @return array<string, string>
     */
    public function translations(string $prefix): array
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return [];
        }

        $messages = [];
        foreach (['en', $this->translator->getLocale()] as $locale) {
            foreach ($this->translator->getCatalogue($locale)->all('messages') as $key => $message) {
                if (str_starts_with($key, $prefix)) {
                    $messages[substr($key, \strlen($prefix))] = $message;
                }
            }
        }

        return $messages;
    }

    /**
     * Describes a library image for the editor and the image picker.
     *
     * @return array{id: int|null, thumb: string, url: string, alt: string, name: string}
     */
    public function describeMedia(Media $media): array
    {
        return [
            'id' => $media->getId(),
            'thumb' => $this->cms->mediaUrl($media, 'thumb'),
            'url' => $this->cms->mediaUrl($media, 'medium'),
            'alt' => $media->getAlt(),
            'name' => $media->getOriginalName(),
        ];
    }

    /**
     * Thumbnails for every image referenced by the given blocks, keyed by media id.
     *
     * @param array<array-key, mixed> $blocks
     *
     * @return array<int, array{id: int|null, thumb: string, url: string, alt: string, name: string}>
     */
    private function mediaMap(array $blocks): array
    {
        $ids = $this->collectMediaIds($blocks);
        if ([] === $ids) {
            return [];
        }

        $map = [];
        foreach ($this->mediaRepository->findBy(['id' => $ids]) as $media) {
            $map[(int) $media->getId()] = $this->describeMedia($media);
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $blocks
     *
     * @return list<int>
     */
    private function collectMediaIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (!\is_array($block) || !\is_string($block['type'] ?? null) || !$this->registry->has($block['type'])) {
                continue;
            }

            $data = \is_array($block['data'] ?? null) ? $block['data'] : [];
            $ids = [...$ids, ...$this->collectFieldMediaIds($this->registry->get($block['type'])->getFields(), $data)];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<Field>         $fields
     * @param array<mixed, mixed> $data
     *
     * @return list<int>
     */
    private function collectFieldMediaIds(array $fields, array $data): array
    {
        $ids = [];
        foreach ($fields as $field) {
            $value = $data[$field->name] ?? null;
            if (Field::IMAGE === $field->type && \is_int($value)) {
                $ids[] = $value;
            } elseif (Field::IMAGES === $field->type && \is_array($value)) {
                $ids = [...$ids, ...array_filter($value, is_int(...))];
            } elseif (Field::BLOCKS === $field->type && \is_array($value)) {
                $ids = [...$ids, ...$this->collectMediaIds($value)];
            } elseif (Field::ITEMS === $field->type && \is_array($value)) {
                foreach ($value as $item) {
                    if (\is_array($item)) {
                        $ids = [...$ids, ...$this->collectFieldMediaIds($field->fields, $item)];
                    }
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Uses a DQL update on purpose: it skips the lifecycle callbacks, so an
     * autosave does not change the "last changed" date of the content.
     *
     * @param array{fields: array<string, string>, blocks: list<array{type: string, data: array<string, mixed>}>, savedAt: string}|null $autosave
     */
    private function writeAutosave(Content $content, ?array $autosave): void
    {
        $this->entityManager->createQueryBuilder()
            ->update($content::class, 'c')
            ->set('c.autosave', ':autosave')
            ->where('c.id = :id')
            ->setParameter('autosave', $autosave, 'json')
            ->setParameter('id', $content->getId())
            ->getQuery()
            ->execute();

        $content->setAutosave($autosave);
    }
}
