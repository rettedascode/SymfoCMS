<?php

namespace App\Service;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Repository\FormSubmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Handles what visitors send through a form: spam checks, validation,
 * storing the submission and notifying the site owner.
 */
final class FormSubmitter
{
    public const RETENTION_SETTING = 'forms.retention_days';

    private const MAX_TEXT_LENGTH = 500;
    private const MAX_TEXTAREA_LENGTH = 5000;

    public function __construct(
        private readonly SpamGuard $spamGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly FormSubmissionRepository $submissions,
        private readonly ValidatorInterface $validator,
        private readonly MailerInterface $mailer,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.form_submission')]
        private readonly RateLimiterFactory $rateLimiter,
        #[Autowire(param: 'app.mail_from')]
        private readonly string $mailFrom,
        #[Autowire(param: 'app.site_name')]
        private readonly string $siteName,
    ) {
    }

    /**
     * @return array{status: 'success'|'error', errors: array<string, string>, values: array<string, string>}
     *                                                                                                        "errors" maps field ids (or "_form") to translation keys of the validators domain
     */
    public function submit(Form $form, Request $request): array
    {
        $values = $this->readValues($form, $request);

        $spam = $this->spamGuard->check($request);
        if (SpamGuard::BOT === $spam) {
            // Do not tell a bot that it was recognised.
            return ['status' => 'success', 'errors' => [], 'values' => []];
        }
        if (SpamGuard::TOO_FAST === $spam) {
            return ['status' => 'error', 'errors' => ['_form' => 'form.too_fast'], 'values' => $values];
        }

        $errors = $this->validate($form, $values);
        if ([] !== $errors) {
            return ['status' => 'error', 'errors' => $errors, 'values' => $values];
        }

        // Counted only for acceptable submissions, so fixing a typo does not use up the allowance.
        if (!$this->rateLimiter->create('form-'.$form->getId().'-'.$request->getClientIp())->consume()->isAccepted()) {
            return ['status' => 'error', 'errors' => ['_form' => 'form.rate_limited'], 'values' => $values];
        }

        $answers = [];
        foreach ($form->getFields() as $field) {
            $answers[] = ['label' => $field['label'], 'value' => $this->displayValue($field, $values[$field['id']] ?? '')];
        }

        $referer = (string) $request->headers->get('referer');
        $submission = new FormSubmission($form, $answers, '' !== $referer ? mb_substr($referer, 0, 500) : null);
        $this->entityManager->persist($submission);
        $this->entityManager->flush();

        $this->notify($form, $submission, $values);
        $this->purgeExpired();

        return ['status' => 'success', 'errors' => [], 'values' => []];
    }

    /**
     * Deletes submissions older than the retention period chosen in the settings.
     *
     * @return int number of deleted submissions
     */
    public function purgeExpired(): int
    {
        $days = $this->settings->get(self::RETENTION_SETTING);
        if (!\is_int($days) || $days < 1) {
            return 0;
        }

        return $this->submissions->deleteOlderThan(new \DateTimeImmutable('-'.$days.' days'));
    }

    /**
     * @return array<string, string> field id => submitted text ("1" for a ticked checkbox)
     */
    private function readValues(Form $form, Request $request): array
    {
        $input = $request->request->all('fields');
        $values = [];

        foreach ($form->getFields() as $field) {
            $raw = $input[$field['id']] ?? '';
            $value = \is_scalar($raw) ? trim((string) $raw) : '';
            // Remove control characters, keep line breaks in long texts.
            $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
            if (FormDefinition::TEXTAREA !== $field['type']) {
                $value = (string) preg_replace('/\s+/u', ' ', $value);
            }
            $values[$field['id']] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string> field id => translation key
     */
    private function validate(Form $form, array $values): array
    {
        $errors = [];

        foreach ($form->getFields() as $field) {
            $value = $values[$field['id']] ?? '';
            $isCheckbox = \in_array($field['type'], [FormDefinition::CHECKBOX, FormDefinition::CONSENT], true);

            if ('' === $value || ($isCheckbox && '1' !== $value)) {
                if ($field['required']) {
                    $errors[$field['id']] = FormDefinition::CONSENT === $field['type'] ? 'form.consent_required' : 'form.required';
                }
                continue;
            }

            $error = match ($field['type']) {
                FormDefinition::EMAIL => \count($this->validator->validate($value, [new Assert\Email(), new Assert\Length(max: 180)])) > 0 ? 'form.email_invalid' : null,
                FormDefinition::SELECT => \in_array($value, $field['options'], true) ? null : 'form.choice_invalid',
                FormDefinition::TEXTAREA => mb_strlen($value) > self::MAX_TEXTAREA_LENGTH ? 'form.too_long' : null,
                FormDefinition::TEXT => mb_strlen($value) > self::MAX_TEXT_LENGTH ? 'form.too_long' : null,
                default => null,
            };
            if (null !== $error) {
                $errors[$field['id']] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param array{id: string, type: string, label: string, required: bool, options: list<string>} $field
     */
    private function displayValue(array $field, string $value): string
    {
        if (\in_array($field['type'], [FormDefinition::CHECKBOX, FormDefinition::CONSENT], true)) {
            return '1' === $value ? '✓' : '–';
        }

        return $value;
    }

    /**
     * @param array<string, string> $values
     */
    private function notify(Form $form, FormSubmission $submission, array $values): void
    {
        if (null === $form->getNotifyEmail()) {
            return;
        }

        $lines = [];
        foreach ($submission->getAnswers() as $answer) {
            $lines[] = $answer['label'].":\n".$answer['value']."\n";
        }
        if (null !== $submission->getPageUrl()) {
            $lines[] = '-- '."\n".$submission->getPageUrl();
        }

        $email = (new Email())
            ->from(new Address($this->mailFrom, $this->siteName))
            ->to($form->getNotifyEmail())
            ->subject(\sprintf('[%s] %s', $this->siteName, $form->getName()))
            ->text(implode("\n", $lines));

        // Answering the notification should reach the person who filled in the form.
        foreach ($form->getFields() as $field) {
            if (FormDefinition::EMAIL === $field['type'] && '' !== ($values[$field['id']] ?? '')) {
                $email->replyTo($values[$field['id']]);
                break;
            }
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            // The submission is stored and visible in the inbox; a mail problem must not turn it into an error for the visitor.
            $this->logger->error('Form notification could not be sent: {message}', ['message' => $exception->getMessage(), 'form' => $form->getId()]);
        }
    }
}
