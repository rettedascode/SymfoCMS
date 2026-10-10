<?php

namespace App\Form;

use App\Install\DatabaseSetup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Database step of the setup wizard.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class InstallDatabaseType extends AbstractType
{
    private const LABELS = [
        DatabaseSetup::SQLITE => 'install.database.sqlite',
        DatabaseSetup::MYSQL => 'MySQL / MariaDB',
        DatabaseSetup::POSTGRESQL => 'PostgreSQL',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['drivers'] as $driver) {
            $choices[self::LABELS[$driver]] = $driver;
        }

        $builder
            ->add('driver', ChoiceType::class, ['label' => 'install.database.driver', 'choices' => $choices, 'expanded' => true])
            ->add('host', TextType::class, ['label' => 'install.database.host', 'required' => false, 'constraints' => [new Assert\Length(max: 255), new Assert\Regex('/^[\w.:\[\]-]*$/')]])
            ->add('port', IntegerType::class, ['label' => 'install.database.port', 'help' => 'install.database.port_help', 'required' => false, 'constraints' => [new Assert\Range(min: 1, max: 65535)]])
            ->add('name', TextType::class, ['label' => 'install.database.name', 'required' => false, 'constraints' => [new Assert\Length(max: 64)]])
            ->add('user', TextType::class, ['label' => 'install.database.user', 'required' => false, 'constraints' => [new Assert\Length(max: 128)], 'attr' => ['autocomplete' => 'off']])
            ->add('password', PasswordType::class, ['label' => 'install.database.password', 'required' => false, 'always_empty' => false, 'attr' => ['autocomplete' => 'off']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'drivers' => [DatabaseSetup::SQLITE],
            'constraints' => [new Assert\Callback(self::validateServer(...))],
        ]);
        $resolver->setAllowedTypes('drivers', 'array');
    }

    /**
     * A database server needs at least a database name and a user; SQLite needs nothing.
     *
     * @param array<string, mixed> $data
     */
    public static function validateServer(array $data, ExecutionContextInterface $context): void
    {
        if (DatabaseSetup::SQLITE === ($data['driver'] ?? null)) {
            return;
        }

        foreach (['name', 'user'] as $field) {
            if ('' === trim((string) ($data[$field] ?? ''))) {
                $context->buildViolation('install.database.required')->atPath('['.$field.']')->addViolation();
            }
        }
    }
}
