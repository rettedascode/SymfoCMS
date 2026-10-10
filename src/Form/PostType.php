<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Post;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Post>
 */
final class PostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $post = $builder->getData();

        $builder
            ->add('excerpt', TextareaType::class, [
                'label' => 'post.excerpt',
                'help' => 'post.excerpt_help',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 600],
            ])
            ->add('coverImage', MediaPickerType::class, [
                'label' => 'post.cover_image',
                'help' => 'post.cover_image_help',
            ])
            ->add('category', EntityType::class, [
                'label' => 'post.category',
                'class' => Category::class,
                'choice_label' => 'name',
                // Categories belong to one language: offer only those of the post's language.
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('c')
                    ->where('c.locale = :locale')
                    ->setParameter('locale', $post instanceof Post ? $post->getLocale() : '')
                    ->orderBy('c.position', 'ASC')
                    ->addOrderBy('c.name', 'ASC'),
                'required' => false,
                'placeholder' => 'post.no_category',
            ])
            ->add('publishedAt', DateTimeType::class, [
                'label' => 'post.published_at',
                'help' => 'post.published_at_help',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ]);

        // Only people who manage all posts may hand a post to somebody else.
        if ($options['can_change_author']) {
            $builder->add('author', EntityType::class, [
                'label' => 'post.author',
                'class' => User::class,
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('u')->orderBy('u.name', 'ASC'),
                'required' => false,
                'placeholder' => 'post.no_author',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Post::class,
            'can_change_author' => false,
        ]);
        $resolver->setAllowedTypes('can_change_author', 'bool');
    }

    public function getParent(): string
    {
        return ContentType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'post';
    }
}
