<?php

namespace App\Form;

use App\Theme\Fonts;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Logo, colours and fonts of the active theme. The colour and font fields come from the theme's manifest.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class ThemeSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('logo', MediaPickerType::class, ['label' => 'theme.logo', 'help' => 'theme.logo_help']);

        foreach ($options['colors'] as $name => $definition) {
            $builder->add('color_'.$name, ColorType::class, ['label' => $definition['label'], 'html5' => true]);
        }

        foreach ($options['fonts'] as $name => $definition) {
            $builder->add('font_'.$name, ChoiceType::class, ['label' => $definition['label'], 'choices' => array_flip(Fonts::labels())]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['colors' => [], 'fonts' => []]);
        $resolver->setAllowedTypes('colors', 'array');
        $resolver->setAllowedTypes('fonts', 'array');
    }
}
