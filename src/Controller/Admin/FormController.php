<?php

namespace App\Controller\Admin;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Form\FormBuilderType;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Security\Voter\AdminAreaVoter;
use App\Service\ContentEditor;
use App\Service\FormDefinition;
use App\Service\FormSubmitter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The form builder and the inbox of what visitors sent.
 */
#[Route('/admin/forms', name: 'admin_form_')]
#[IsGranted(AdminAreaVoter::MANAGE_FORMS)]
final class FormController extends AdminController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FormSubmissionRepository $submissions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(FormRepository $forms, FormSubmitter $submitter): Response
    {
        // Opening the forms section is a natural moment to remove what is past its retention period.
        $submitter->purgeExpired();

        return $this->render('admin/form/index.html.twig', [
            'forms' => $forms->findBy([], ['name' => 'ASC']),
            'unread' => $this->submissions->countUnreadByForm(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, FormDefinition $definition, ContentEditor $editor): Response
    {
        $form = (new Form())
            ->setSubmitLabel($this->translator->trans('form.default_submit_label'))
            ->setSuccessMessage($this->translator->trans('form.default_success_message'));

        return $this->form($request, $form, $definition, $editor);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Form $form, FormDefinition $definition, ContentEditor $editor): Response
    {
        return $this->form($request, $form, $definition, $editor);
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Form $form): Response
    {
        $this->requireCsrfToken($request);

        // Its submissions are deleted with it; pages that show the form simply stop showing it.
        $this->entityManager->remove($form);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.form_deleted');

        return $this->redirectToRoute('admin_form_index');
    }

    #[Route('/{id<\d+>}/submissions', name: 'submissions', methods: ['GET'])]
    public function submissions(Request $request, Form $form): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->submissions->count(['form' => $form]);

        return $this->render('admin/form/submissions.html.twig', [
            'form' => $form,
            'submissions' => $this->submissions->findForForm($form, $page, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    /**
     * Shows one submission and marks it as read.
     */
    #[Route('/submissions/{id<\d+>}', name: 'submission', methods: ['GET'])]
    public function submission(FormSubmission $submission): Response
    {
        if (!$submission->isRead()) {
            $submission->setRead(true);
            $this->entityManager->flush();
        }

        return $this->render('admin/form/submission.html.twig', ['submission' => $submission]);
    }

    #[Route('/submissions/{id<\d+>}/unread', name: 'submission_unread', methods: ['POST'])]
    public function markUnread(Request $request, FormSubmission $submission): Response
    {
        $this->requireCsrfToken($request);

        $submission->setRead(false);
        $this->entityManager->flush();

        return $this->redirectToRoute('admin_form_submissions', ['id' => $submission->getForm()->getId()]);
    }

    #[Route('/submissions/{id<\d+>}/delete', name: 'submission_delete', methods: ['POST'])]
    public function deleteSubmission(Request $request, FormSubmission $submission): Response
    {
        $this->requireCsrfToken($request);
        $formId = $submission->getForm()->getId();

        $this->entityManager->remove($submission);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.submission_deleted');

        return $this->redirectToRoute('admin_form_submissions', ['id' => $formId]);
    }

    /**
     * All submissions of a form as a CSV file that opens in spreadsheet programs.
     */
    #[Route('/{id<\d+>}/export.csv', name: 'export', methods: ['GET'])]
    public function export(Form $form): StreamedResponse
    {
        $submissions = $this->submissions->findForForm($form, 1, 100000);

        // One column per question that was ever asked, in order of first appearance.
        $columns = [];
        foreach ($submissions as $submission) {
            foreach ($submission->getAnswers() as $answer) {
                $columns[$answer['label']] = true;
            }
        }
        $columns = array_keys($columns);
        $dateLabel = $this->translator->trans('form.submitted_at');

        $response = new StreamedResponse(static function () use ($submissions, $columns, $dateLabel): void {
            $output = fopen('php://output', 'w');
            if (false === $output) {
                return;
            }

            // The byte order mark makes Excel read the file as UTF-8.
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_map(self::csvCell(...), [$dateLabel, ...$columns]), ';', '"', '');

            foreach ($submissions as $submission) {
                $answers = array_column($submission->getAnswers(), 'value', 'label');
                $row = [$submission->getCreatedAt()->format('Y-m-d H:i')];
                foreach ($columns as $column) {
                    $row[] = $answers[$column] ?? '';
                }
                fputcsv($output, array_map(self::csvCell(...), $row), ';', '"', '');
            }
            fclose($output);
        });

        $filename = (preg_replace('/[^a-z0-9]+/', '-', strtolower($form->getName())) ?: 'form').'-'.date('Y-m-d').'.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    /**
     * Visitors control these values. A cell starting with =, +, - or @ would be
     * run as a formula by spreadsheet programs, so such cells are defused.
     */
    private static function csvCell(string $value): string
    {
        return 1 === preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function form(Request $request, Form $entity, FormDefinition $definition, ContentEditor $editor): Response
    {
        $isNew = null === $entity->getId();
        $form = $this->createForm(FormBuilderType::class, $entity);
        $form->handleRequest($request);
        $fields = $entity->getFields();

        if ($form->isSubmitted()) {
            $json = $form->get('fields')->getData();
            $fields = $definition->normalize(json_decode(\is_string($json) ? $json : '[]', true));
            if ([] === $fields) {
                $form->addError(new FormError($this->translator->trans('form.no_fields', [], 'validators')));
            }

            if ($form->isValid()) {
                $entity->setFields($fields);
                $this->entityManager->persist($entity);
                $this->entityManager->flush();
                $this->addFlash('success', $isNew ? 'flash.form_created' : 'flash.saved');

                return $this->redirectToRoute('admin_form_edit', ['id' => $entity->getId()]);
            }
        }

        return $this->render('admin/form/edit.html.twig', [
            'form' => $form,
            'entity' => $entity,
            'builder_config' => [
                'fields' => $fields,
                'i18n' => $editor->translations('form.js.'),
            ],
        ]);
    }
}
