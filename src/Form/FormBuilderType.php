<?php

namespace App\Form;

use App\Entity\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings of a form built in the admin area. The fields themselves are
 * edited by the "form-builder" Stimulus controller and travel as JSON.
 *
 * @extends AbstractType<Form>
 */
final class FormBuilderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'form.name',
                'help' => 'form.name_help',
            ])
            ->add('fields', HiddenType::class, [
                'mapped' => false,
                'attr' => ['data-form-builder-target' => 'input'],
            ])
            ->add('submitLabel', TextType::class, [
                'label' => 'form.submit_label',
            ])
            ->add('successMessage', TextareaType::class, [
                'label' => 'form.success_message',
                'help' => 'form.success_message_help',
                'attr' => ['rows' => 2],
            ])
            ->add('notifyEmail', EmailType::class, [
                'label' => 'form.notify_email',
                'help' => 'form.notify_email_help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Form::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'form';
    }
}
