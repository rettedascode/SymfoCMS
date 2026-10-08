<?php

namespace App\Service;

use App\Block\BlockNormalizer;
use App\Block\BlockRegistry;
use App\Block\Field;
use App\Controller\Admin\AdminController;
use App\Entity\Content;
use App\Entity\Media;
use App\Repository\MediaRepository;
use App\Twig\CmsExtension;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaRepository $mediaRepository,
        private readonly CmsExtension $cms,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * Configuration for the "editor" Stimulus controller.
     *
     * @param list<array{type: string, data: array<string, mixed>}> $blocks blocks to show (the saved ones, or the submitted ones after a validation error)
     * @param array{autosave: string, discard: string}              $urls
     *
     * @return array<string, mixed>
     */
    public function config(Content $content, array $blocks, array $urls, string $kind): array
    {
        $autosave = $content->getAutosave();

        return [
            'kind' => $kind,
            'blocks' => $blocks,
            'autosave' => $autosave,
            'types' => $this->typeDefinitions(),
            'media' => $this->mediaMap([...$blocks, ...($autosave['blocks'] ?? [])]),
            'i18n' => $this->translations('editor.'),
            'csrf' => $this->csrfTokenManager->getToken(AdminController::CSRF_ID)->getValue(),
            'locale' => $this->translator->getLocale(),
            'urls' => $urls + [
                'preview' => $this->urlGenerator->generate('admin_preview'),
                'mediaList' => $this->urlGenerator->generate('admin_media_list'),
                'mediaUpload' => $this->urlGenerator->generate('admin_media_upload'),
            ],
        ];
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
            $fields = [];
            foreach ($type->getFields() as $field) {
                $options = [];
                foreach ($field->options as $value => $label) {
                    $options[] = ['value' => (string) $value, 'label' => $this->translator->trans($label)];
                }

                $fields[] = [
                    'name' => $field->name,
                    'type' => $field->type,
                    'label' => $this->translator->trans($field->label),
                    'help' => null !== $field->help ? $this->translator->trans($field->help) : null,
                    'placeholder' => null !== $field->placeholder ? $this->translator->trans($field->placeholder) : null,
                    'default' => $field->default,
                    'options' => $options,
                ];
            }

            $definitions[] = [
                'type' => $name,
                'label' => $this->translator->trans($type->getLabel()),
                'icon' => $type->getIcon(),
                'layout' => $this->registry->isLayout($type),
                'fields' => $fields,
            ];
        }

        return $definitions;
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

            foreach ($this->registry->get($block['type'])->getFields() as $field) {
                $value = $block['data'][$field->name] ?? null;
                if (Field::IMAGE === $field->type && \is_int($value)) {
                    $ids[] = $value;
                } elseif (Field::BLOCKS === $field->type && \is_array($value)) {
                    $ids = [...$ids, ...$this->collectMediaIds($value)];
                }
            }
        }

        return array_values(array_unique($ids));
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
