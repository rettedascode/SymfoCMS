<?php

namespace App\Tests\Functional;

use App\Entity\Media;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaLibraryTest extends AppWebTestCase
{
    public function testUploadStoresImageWithUniqueNameAndResizedVersions(): void
    {
        $this->loginAs(UserRole::Editor);
        $token = $this->csrfToken('/admin/media', '[data-uploader-csrf-value]', 'data-uploader-csrf-value');

        $this->client->request('POST', '/admin/media/upload', files: ['file' => $this->image(2000, 1000, 'My Holiday Photo.png')], server: ['HTTP_X_CSRF_TOKEN' => $token]);

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('My Holiday Photo.png', $payload['media']['name']);

        $media = $this->em()->getRepository(Media::class)->findOneBy([]);
        self::assertInstanceOf(Media::class, $media);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}\.png$/', $media->getFilename(), 'The stored name must be generated, not taken from the upload.');
        self::assertSame([2000, 1000], [$media->getWidth(), $media->getHeight()]);
        self::assertSame(['thumb', 'medium', 'large'], array_keys($media->getVariants()));
        self::assertSame(['file' => substr($media->getFilename(), 0, 24).'-thumb.png', 'width' => 400, 'height' => 200], $media->getVariants()['thumb']);

        foreach ([$media->getFilename(), ...array_column($media->getVariants(), 'file')] as $file) {
            self::assertFileExists($this->mediaDir().'/'.$file);
        }
        self::assertSame([400, 200], \array_slice((array) getimagesize($this->mediaDir().'/'.$media->getFile('thumb')), 0, 2));
    }

    public function testSmallImagesAreNotEnlarged(): void
    {
        $media = $this->upload($this->image(300, 300));

        self::assertSame([], $media->getVariants());
        self::assertSame($media->getFilename(), $media->getFile('large'));
    }

    public function testFilesThatAreNotImagesAreRejected(): void
    {
        $this->loginAs(UserRole::Editor);
        $token = $this->csrfToken('/admin/media', '[data-uploader-csrf-value]', 'data-uploader-csrf-value');

        $fake = tempnam(sys_get_temp_dir(), 'cms');
        file_put_contents($fake, '<?php echo "not an image";');
        $svg = tempnam(sys_get_temp_dir(), 'cms');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $uploads = [
            // A script pretending to be a PNG, by name and by declared type.
            new UploadedFile($fake, 'photo.png', 'image/png', null, true),
            new UploadedFile($svg, 'logo.svg', 'image/svg+xml', null, true),
        ];

        foreach ($uploads as $upload) {
            $this->client->request('POST', '/admin/media/upload', files: ['file' => $upload], server: ['HTTP_X_CSRF_TOKEN' => $token]);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('not a supported image', (string) $this->client->getResponse()->getContent());
        }

        self::assertSame(0, $this->em()->getRepository(Media::class)->count([]));
    }

    public function testUploadRequiresCsrfToken(): void
    {
        $this->loginAs(UserRole::Editor);

        $this->client->request('POST', '/admin/media/upload', files: ['file' => $this->image(10, 10)]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAltTextCanBeEdited(): void
    {
        $media = $this->upload($this->image(50, 50));

        $this->client->request('GET', '/admin/media/'.$media->getId().'/edit');
        $this->client->submitForm('Save', ['media[alt]' => 'A red square']);

        self::assertResponseRedirects('/admin/media');
        $this->em()->clear();
        self::assertSame('A red square', $this->entity(Media::class, $media->getId())->getAlt());
    }

    public function testPickerListReturnsLibraryAsJson(): void
    {
        $media = $this->upload($this->image(500, 500));

        $this->client->request('GET', '/admin/media/list');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['hasMore']);
        self::assertSame($media->getId(), $payload['items'][0]['id']);
        self::assertStringEndsWith('-thumb.png', $payload['items'][0]['thumb']);
    }

    public function testDeletingRemovesFilesFromDisk(): void
    {
        $media = $this->upload($this->image(1000, 500));
        $files = [$media->getFilename(), ...array_column($media->getVariants(), 'file')];
        self::assertCount(3, $files);

        $token = $this->csrfToken('/admin/media/'.$media->getId().'/edit');
        $this->client->request('POST', '/admin/media/'.$media->getId().'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/admin/media');
        foreach ($files as $file) {
            self::assertFileDoesNotExist($this->mediaDir().'/'.$file);
        }
    }

    public function testAuthorsMayUploadButOnlyManageTheirOwnImages(): void
    {
        $foreign = $this->upload($this->image(50, 50));
        $foreignId = $foreign->getId();

        $author = $this->createUser(UserRole::Author);
        $this->client->loginUser($author);
        $token = $this->csrfToken('/admin/media', '[data-uploader-csrf-value]', 'data-uploader-csrf-value');

        // Uploading works and the author owns the result ...
        $this->client->request('POST', '/admin/media/upload', files: ['file' => $this->image(60, 60)], server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(201);
        $ownId = json_decode((string) $this->client->getResponse()->getContent(), true)['media']['id'];

        $this->client->request('POST', '/admin/media/'.$ownId.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/media');

        // ... but images of other people are read-only.
        $this->client->request('GET', '/admin/media/'.$foreignId.'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button.button--danger');

        $this->client->request('POST', '/admin/media/'.$foreignId.'/edit', ['media' => ['alt' => 'Changed']]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/media/'.$foreignId.'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testImageBlockRendersResponsiveImage(): void
    {
        $media = $this->upload($this->image(2000, 1000));
        $media->setAlt('Library description');
        $page = $this->createPage('Gallery', \App\Enum\ContentStatus::Published);
        $page->setBlocks([
            ['type' => 'image', 'data' => ['image' => $media->getId(), 'alt' => '', 'caption' => 'A caption']],
            ['type' => 'image', 'data' => ['image' => 99999, 'alt' => 'Deleted image', 'caption' => '']],
        ]);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/gallery');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.block-image'), 'A block whose image was deleted renders nothing.');
        $image = $crawler->filter('.block-image img');
        self::assertSame('Library description', $image->attr('alt'));
        self::assertStringContainsString('-large.png', (string) $image->attr('src'));
        self::assertStringContainsString('400w', (string) $image->attr('srcset'));
        self::assertSelectorTextSame('.block-image figcaption', 'A caption');
    }

    private function upload(UploadedFile $file): Media
    {
        $this->loginAs(UserRole::Editor);
        $token = $this->csrfToken('/admin/media', '[data-uploader-csrf-value]', 'data-uploader-csrf-value');
        $this->client->request('POST', '/admin/media/upload', files: ['file' => $file], server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(201);

        $id = json_decode((string) $this->client->getResponse()->getContent(), true)['media']['id'];

        return $this->entity(Media::class, $id);
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function image(int $width, int $height, string $name = 'test.png'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms');
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
