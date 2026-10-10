<?php

namespace App\Form;

use App\Service\LocaleChoices;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Last step of the setup wizard: the website and its first administrator.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class InstallSiteType extends AbstractType
{
    public function __construct(private readonly LocaleChoices $locales)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('siteName', TextType::class, [
                'label' => 'settings.site_name',
                'help' => 'install.site.name_help',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
                'attr' => ['maxlength' => 100],
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'install.site.locale',
                'help' => 'install.site.locale_help',
                'choices' => $this->locales->choices(),
                'choice_translation_domain' => false,
            ])
            ->add('adminName', TextType::class, [
                'label' => 'install.site.admin_name',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)],
                'attr' => ['autocomplete' => 'name', 'maxlength' => 120],
            ])
            ->add('email', EmailType::class, [
                'label' => 'install.site.email',
                'help' => 'install.site.email_help',
                'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)],
                'attr' => ['autocomplete' => 'username'],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'install.site.password',
                'help' => 'install.site.password_help',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 8, max: 4096)],
                'attr' => ['autocomplete' => 'new-password', 'minlength' => 8],
            ]);
    }
}
