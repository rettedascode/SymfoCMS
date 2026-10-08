<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * First step of creating a page or a post: only the title is asked for.
 *
 * @extends AbstractType<array{title: string}>
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
    }

    public function getBlockPrefix(): string
    {
        return 'content';
    }
}
