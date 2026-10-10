<?php

namespace App\Controller\Admin;

use App\Security\Voter\AdminAreaVoter;
use App\Service\SiteInfo;
use App\Service\SlugGenerator;
use App\Transfer\SiteExporter;
use App\Transfer\SiteImporter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Export and import": the whole website as one file, to move it to another
 * installation or to keep a copy.
 */
#[Route('/admin/transfer', name: 'admin_transfer_')]
#[IsGranted(AdminAreaVoter::TRANSFER_SITE)]
final class TransferController extends AdminController
{
    public function __construct(
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $tempDir,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/transfer/index.html.twig', [
            'max_upload_mb' => (int) floor(UploadedFile::getMaxFilesize() / 1024 / 1024),
            'error' => null,
        ]);
    }

    #[Route('/export', name: 'export', methods: ['POST'])]
    public function export(Request $request, SiteExporter $exporter, SiteInfo $site, SlugGenerator $slugs): Response
    {
        $this->requireCsrfToken($request);

        $path = (new Filesystem())->tempnam($this->tempDir, 'export', '.zip');
        $exporter->export($path);

        $name = \sprintf('%s-%s.zip', $slugs->slugify($site->name()) ?: 'website', date('Y-m-d'));

        return (new BinaryFileResponse($path))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name)
            ->deleteFileAfterSend();
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request, SiteImporter $importer): Response
    {
        $this->requireCsrfToken($request);

        $file = $request->files->get('archive');
        $replace = 'replace' === $request->request->get('mode');
        $error = match (true) {
            !$file instanceof UploadedFile => 'transfer.error.no_file',
            !$file->isValid() => 'transfer.error.upload_failed',
            // Replacing deletes content, so it has to be confirmed explicitly.
            $replace && !$request->request->getBoolean('confirm') => 'transfer.error.confirm',
            default => null,
        };

        if (null === $error) {
            try {
                @set_time_limit(600);
                $report = $importer->import($file->getPathname(), $replace, $this->currentUser());

                return $this->render('admin/transfer/report.html.twig', ['report' => $report, 'heading' => 'transfer.report.heading']);
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('admin/transfer/index.html.twig', [
            'max_upload_mb' => (int) floor(UploadedFile::getMaxFilesize() / 1024 / 1024),
            'error' => $error,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
