<?php

namespace App\Form;

use App\Controller\Admin\AdminController;
use App\Entity\Media;
use App\Repository\MediaRepository;
use App\Service\ContentEditor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Form field that selects one image from the media library through the picker dialog.
 * Rendered by the "media_picker_widget" block in templates/admin/form_theme.html.twig.
 *
 * @extends AbstractType<Media|null>
 */
final class MediaPickerType extends AbstractType
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
        private readonly ContentEditor $editor,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The browser only sends the id of the chosen image.
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?Media $media): string => (string) $media?->getId(),
            fn (?string $id): ?Media => is_numeric($id) ? $this->mediaRepository->find((int) $id) : null,
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $media = $form->getData();

        $view->vars['picker_config'] = [
            'media' => $media instanceof Media ? $this->editor->describeMedia($media) : null,
            'listUrl' => $this->urlGenerator->generate('admin_media_list'),
            'uploadUrl' => $this->urlGenerator->generate('admin_media_upload'),
            'csrf' => $this->csrfTokenManager->getToken(AdminController::CSRF_ID)->getValue(),
            'i18n' => $this->editor->translations('editor.'),
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['required' => false, 'error_bubbling' => false]);
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'media_picker';
    }
}
