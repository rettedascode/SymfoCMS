<?php

namespace App\Form;

use App\Entity\SharedBlock;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SharedBlock>
 */
final class SharedBlockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'shared_block.name',
                'help' => 'shared_block.name_help',
                'attr' => ['class' => 'editor-title', 'data-editor-target' => 'title', 'autocomplete' => 'off'],
            ])
            // The block list travels as JSON in a hidden field and is normalized by the controller.
            ->add('blocks', HiddenType::class, [
                'mapped' => false,
                'attr' => ['data-editor-target' => 'input'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SharedBlock::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'shared_block';
    }
}
