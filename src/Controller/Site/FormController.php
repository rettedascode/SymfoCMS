<?php

namespace App\Controller\Site;

use App\Entity\Form;
use App\Service\FormSubmitter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormController extends AbstractController
{
    /** Session key prefix under which the outcome of a submission waits for the next page view. */
    public const RESULT_KEY = 'form_result_';

    /**
     * Receives a form sent from any page, then sends the visitor back to that
     * page, where the form block shows the confirmation or the errors.
     */
    #[Route('/form/{id<\d+>}', name: 'app_form_submit', methods: ['POST'])]
    public function submit(Request $request, Form $form, FormSubmitter $submitter): Response
    {
        $result = $submitter->submit($form, $request);
        $request->getSession()->set(self::RESULT_KEY.$form->getId(), $result);

        // The anchor is the confirmation or error message: browsers scroll to it and move the focus there.
        return $this->redirect($this->returnPath($request).'#form-'.$form->getId().'-message', Response::HTTP_SEE_OTHER);
    }

    /**
     * The page the form was on - but only if it belongs to this website.
     */
    private function returnPath(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        $parts = parse_url($referer);

        if (\is_array($parts) && ($parts['host'] ?? null) === $request->getHost() && isset($parts['path'])) {
            return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        return $this->generateUrl('app_home');
    }
}
