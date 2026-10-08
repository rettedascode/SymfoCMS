<?php

namespace App\Form;

use App\Entity\Page;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @extends AbstractType<Page>
 */
final class PageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('homepage', CheckboxType::class, [
            'label' => 'page.homepage',
            'help' => 'page.homepage_help',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Page::class,
            'constraints' => [new Assert\Callback(self::validateSlug(...))],
        ]);
    }

    public function getParent(): string
    {
        return ContentType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'page';
    }

    public static function validateSlug(Page $page, ExecutionContextInterface $context): void
    {
        if (\in_array($page->getSlug(), Page::RESERVED_SLUGS, true)) {
            $context->buildViolation('content.slug_reserved')->atPath('slug')->addViolation();
        }
    }
}
