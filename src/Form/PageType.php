<?php

namespace App\Form;

use App\Entity\Page;
use App\Service\PageTree;
use App\Service\SlugGenerator;
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
    public function __construct(
        private readonly SlugGenerator $slugGenerator,
        private readonly PageTree $tree,
    ) {
    }

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
            'constraints' => [new Assert\Callback($this->validateSlug(...))],
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

    public function validateSlug(Page $page, ExecutionContextInterface $context): void
    {
        if ($this->slugGenerator->isReservedForPages($page->getSlug())) {
            $context->buildViolation('content.slug_reserved')->atPath('slug')->addViolation();
        }

        // The homepage answers at "/", so it can neither be a child page nor have child pages.
        if ($page->isHomepage() && (null !== $page->getParent() || [] !== $this->tree->children($page, $page->getLocale()))) {
            $context->buildViolation('page.tree.error.homepage')->atPath('homepage')->addViolation();
        }
    }
}
