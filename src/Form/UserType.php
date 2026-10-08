<?php

namespace App\Form;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\LocaleChoices;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<User>
 */
final class UserType extends AbstractType
{
    public function __construct(private readonly LocaleChoices $localeChoices)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordConstraints = [new Assert\Length(min: 8, max: 4096, minMessage: 'user.password_too_short')];
        if ($options['require_password']) {
            $passwordConstraints[] = new Assert\NotBlank();
        }

        $builder
            ->add('name', TextType::class, ['label' => 'user.name'])
            ->add('email', EmailType::class, ['label' => 'user.email'])
            ->add('plainPassword', PasswordType::class, [
                'label' => $options['require_password'] ? 'user.password' : 'user.new_password',
                'help' => $options['require_password'] ? 'user.password_help' : 'user.new_password_help',
                'mapped' => false,
                'required' => $options['require_password'],
                'constraints' => $passwordConstraints,
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('role', EnumType::class, [
                'label' => 'user.role',
                'class' => UserRole::class,
                'choice_label' => static fn (UserRole $role): string => $role->label(),
                'expanded' => true,
                'disabled' => !$options['can_change_access'],
                'help' => $options['can_change_access'] ? null : 'user.own_access_help',
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'user.language',
                'choices' => $this->localeChoices->choices(),
                'choice_translation_domain' => false,
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'user.active',
                'help' => 'user.active_help',
                'required' => false,
                'disabled' => !$options['can_change_access'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => false,
            'can_change_access' => true,
        ]);
        $resolver->setAllowedTypes('require_password', 'bool');
        $resolver->setAllowedTypes('can_change_access', 'bool');
    }
}
