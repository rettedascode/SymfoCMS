<?php

namespace App\Form;

use App\Entity\User;
use App\Service\LocaleChoices;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<User>
 */
final class AccountType extends AbstractType
{
    public function __construct(
        private readonly LocaleChoices $localeChoices,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'user.name'])
            ->add('locale', ChoiceType::class, [
                'label' => 'user.language',
                'choices' => $this->localeChoices->choices(),
                'choice_translation_domain' => false,
            ])
            ->add('currentPassword', PasswordType::class, [
                'label' => 'user.current_password',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => 'user.new_password',
                'help' => 'user.new_password_help',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Assert\Length(min: 8, max: 4096, minMessage: 'user.password_too_short')],
                'attr' => ['autocomplete' => 'new-password'],
            ])
            // Changing the password requires knowing the current one.
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                $user = $event->getData();
                $newPassword = (string) $form->get('newPassword')->getData();
                $currentPassword = (string) $form->get('currentPassword')->getData();

                if ('' !== $newPassword && $user instanceof User && !$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $form->get('currentPassword')->addError(new FormError($this->translator->trans('user.current_password_wrong', [], 'validators')));
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
