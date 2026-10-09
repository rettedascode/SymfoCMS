<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * First step of creating a page or a post: the title and, for pages, a template to start from.
 *
 * @extends AbstractType<array{title: string, template?: string}>
 */
final class NewContentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class, [
            'label' => 'label.title',
            'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 200)],
            'attr' => ['autofocus' => true, 'autocomplete' => 'off'],
        ]);

        if ([] !== $options['templates']) {
            $builder->add('template', ChoiceType::class, [
                'label' => 'template.choose',
                'choices' => array_combine(array_keys($options['templates']), array_keys($options['templates'])),
                'choice_label' => static fn (string $key): string => $options['templates'][$key]['name'],
                'choice_attr' => static fn (string $key): array => ['data-description' => $options['templates'][$key]['description']],
                'choice_translation_domain' => false,
                'expanded' => true,
                'data' => array_key_first($options['templates']),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // template key => ['name' => ..., 'description' => ...]; empty for content without templates
        $resolver->setDefault('templates', []);
        $resolver->setAllowedTypes('templates', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'content';
    }
}
