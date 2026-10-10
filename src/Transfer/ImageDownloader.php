<?php

namespace App\Transfer;

use App\Entity\Media;
use App\Entity\User;
use App\Exception\InvalidUploadException;
use App\Service\MediaManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches an image from another website into the media library.
 *
 * The addresses come from an import file, so they are not trusted: only
 * http(s) is used, addresses inside the own network are refused (also after
 * a redirect), downloads are limited in size and time, and the result has
 * to pass the same checks as an uploaded image.
 */
final class ImageDownloader
{
    private readonly HttpClientInterface $client;

    public function __construct(
        HttpClientInterface $client,
        private readonly MediaManager $mediaManager,
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $tempDir,
    ) {
        $this->client = new NoPrivateNetworkHttpClient($client);
    }

    public function download(string $url, ?User $user): ?Media
    {
        if (1 !== preg_match('~^https?://[^\s]+$~i', $url)) {
            return null;
        }

        $limit = $this->mediaManager->maxUploadMb() * 1024 * 1024;
        $filesystem = new Filesystem();
        $temp = $filesystem->tempnam($this->tempDir, 'download');

        try {
            $response = $this->client->request('GET', $url, ['timeout' => 15, 'max_duration' => 60, 'max_redirects' => 3, 'headers' => ['Accept' => 'image/*']]);
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $handle = fopen($temp, 'w');
            if (false === $handle) {
                return null;
            }

            $size = 0;
            foreach ($this->client->stream($response) as $chunk) {
                $size += \strlen($chunk->getContent());
                if ($size > $limit) {
                    $response->cancel();
                    fclose($handle);

                    return null;
                }
                fwrite($handle, $chunk->getContent());
            }
            fclose($handle);

            $name = basename((string) parse_url($url, \PHP_URL_PATH)) ?: 'image';

            return $this->mediaManager->upload(new UploadedFile($temp, mb_substr(rawurldecode($name), 0, 255), null, null, true), $user);
        } catch (ExceptionInterface|InvalidUploadException) {
            // Not reachable, not allowed or not an image: the caller reports it.
            return null;
        } finally {
            $filesystem->remove($temp);
        }
    }
}
