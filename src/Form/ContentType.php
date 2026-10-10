<?php

namespace App\Form;

use App\Entity\Content;
use App\Enum\ContentStatus;
use App\Service\SlugGenerator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Fields shared by the page and the post form. Used as parent type.
 *
 * @extends AbstractType<Content>
 */
final class ContentType extends AbstractType
{
    public function __construct(private readonly SlugGenerator $slugGenerator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Only the statuses the user may choose are offered; with less than two there is nothing to choose.
        if (\count($options['statuses']) > 1) {
            $builder->add('status', EnumType::class, [
                'label' => 'label.status',
                'class' => ContentStatus::class,
                'choices' => $options['statuses'],
                'choice_label' => static fn (ContentStatus $status): string => $status->label(),
                'expanded' => true,
            ]);
        }

        $builder
            ->add('title', TextType::class, [
                'label' => 'label.title',
                'attr' => ['class' => 'editor-title', 'data-editor-target' => 'title', 'autocomplete' => 'off'],
            ])
            ->add('slug', TextType::class, [
                'label' => 'content.slug',
                'help' => 'content.slug_help',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('seoTitle', TextType::class, [
                'label' => 'content.seo_title',
                'help' => 'content.seo_title_help',
                'required' => false,
            ])
            ->add('shareImage', MediaPickerType::class, [
                'label' => 'content.share_image',
                'help' => 'content.share_image_help',
            ])
            ->add('metaDescription', TextareaType::class, [
                'label' => 'content.meta_description',
                'help' => 'content.meta_description_help',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 320],
            ])
            // The block list travels as JSON in a hidden field and is normalized by the controller.
            ->add('blocks', HiddenType::class, [
                'mapped' => false,
                'attr' => ['data-editor-target' => 'input'],
            ])
            // People type "About us", not "about-us": clean the address up instead of rejecting it.
            ->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
                $content = $event->getData();
                if (!$content instanceof Content) {
                    return;
                }

                $slug = $this->slugGenerator->slugify($content->getSlug());
                if ('' === $slug && '' !== $content->getTitle()) {
                    $slug = $this->slugGenerator->unique($content->getTitle(), $content::class, $content->getLocale(), $content->getId());
                }
                $content->setSlug($slug);
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['statuses' => [ContentStatus::Draft, ContentStatus::Published]]);
        $resolver->setAllowedTypes('statuses', 'array');
    }
}
