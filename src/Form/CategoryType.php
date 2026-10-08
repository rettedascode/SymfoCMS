<?php

namespace App\Form;

use App\Entity\Category;
use App\Service\SlugGenerator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Category>
 */
final class CategoryType extends AbstractType
{
    public function __construct(private readonly SlugGenerator $slugGenerator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['label' => 'category.name']);

        if ($options['with_slug']) {
            $builder->add('slug', TextType::class, [
                'label' => 'content.slug',
                'help' => 'category.slug_help',
                'required' => false,
                'empty_data' => '',
            ]);
        }

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $category = $event->getData();
            if (!$category instanceof Category) {
                return;
            }

            $slug = $this->slugGenerator->slugify($category->getSlug());
            if ('' === $slug && '' !== $category->getName()) {
                $slug = $this->slugGenerator->unique($category->getName(), Category::class, $category->getId());
            }
            $category->setSlug($slug);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Category::class, 'with_slug' => true]);
        $resolver->setAllowedTypes('with_slug', 'bool');
    }
}
