<?php

namespace App\Twig;

use App\Controller\Site\FormController;
use App\Repository\FormRepository;
use App\Service\SpamGuard;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * Renders a form built in the admin area, including the outcome of a submission that was just made.
 */
final class FormExtension
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly SpamGuard $spamGuard,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Empty when the form does not exist (any more).
     */
    #[AsTwigFunction('render_form', isSafe: ['html'], needsEnvironment: true)]
    public function renderForm(Environment $twig, mixed $id): string
    {
        $form = is_numeric($id) ? $this->forms->find((int) $id) : null;
        if (null === $form) {
            return '';
        }

        // Only look into the session if the visitor already has one: showing a form must not start a session.
        $result = null;
        $request = $this->requestStack->getMainRequest();
        if (null !== $request && $request->hasPreviousSession()) {
            $result = $request->getSession()->remove(FormController::RESULT_KEY.$form->getId());
        }

        return $twig->render('site/_form.html.twig', [
            'form' => $form,
            'result' => \is_array($result) ? $result : null,
            'started' => $this->spamGuard->createTimestamp(),
            'honeypot' => SpamGuard::HONEYPOT,
            'timestamp_field' => SpamGuard::TIMESTAMP,
        ]);
    }
}
