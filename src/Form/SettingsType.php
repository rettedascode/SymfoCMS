<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\Post;
use App\Service\ContentLocales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @extends AbstractType<array{locales: string, retentionDays: int|null}>
 */
final class SettingsType extends AbstractType
{
    public function __construct(
        private readonly ContentLocales $locales,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('locales', TextType::class, [
            'label' => 'settings.locales',
            'help' => 'settings.locales_help',
            'constraints' => [new Assert\NotBlank(), new Assert\Callback($this->validateLocales(...))],
            'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);

        $builder->add('retentionDays', IntegerType::class, [
            'label' => 'settings.retention_days',
            'help' => 'settings.retention_days_help',
            'required' => false,
            'constraints' => [new Assert\Range(min: 1, max: 3650)],
            'attr' => ['min' => 1, 'max' => 3650, 'inputmode' => 'numeric'],
        ]);
    }

    /**
     * Turns "en, de fr" into ['en', 'de', 'fr'].
     *
     * @return list<string>
     */
    public static function parseLocales(string $input): array
    {
        $codes = preg_split('/[\s,;]+/', strtolower(str_replace('_', '-', $input)), -1, \PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(false === $codes ? [] : $codes));
    }

    public function validateLocales(?string $value, ExecutionContextInterface $context): void
    {
        $codes = self::parseLocales((string) $value);

        foreach ($codes as $code) {
            if (!ContentLocales::isValidCode($code)) {
                $context->buildViolation('settings.locale_invalid')->setParameter('%code%', $code)->addViolation();

                return;
            }
        }

        if (\count($codes) > ContentLocales::MAX_LOCALES) {
            $context->buildViolation('settings.too_many_locales')->setParameter('%limit%', (string) ContentLocales::MAX_LOCALES)->addViolation();
        }

        // A language can only be removed once nothing is written in it any more,
        // otherwise its pages would silently disappear from the website.
        foreach (array_diff($this->locales->all(), $codes) as $removed) {
            if ($this->hasContent($removed)) {
                $context->buildViolation('settings.locale_in_use')->setParameter('%name%', $this->locales->name($removed))->addViolation();
            }
        }

        // A page of the default language cannot have the address of a language prefix ("/de").
        foreach ($codes as $code) {
            if (null !== $this->entityManager->getRepository(Page::class)->findOneBy(['slug' => $code])) {
                $context->buildViolation('settings.locale_conflicts_with_page')->setParameter('%code%', $code)->addViolation();
            }
        }
    }

    private function hasContent(string $locale): bool
    {
        foreach ([Page::class, Post::class, Category::class, MenuItem::class] as $class) {
            if ($this->entityManager->getRepository($class)->count(['locale' => $locale]) > 0) {
                return true;
            }
        }

        return false;
    }
}
