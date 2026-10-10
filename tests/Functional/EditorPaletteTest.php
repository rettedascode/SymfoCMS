<?php

namespace App\Tests\Functional;

use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

/**
 * What the server sends for the block palette and for files dropped into the editor.
 * Dragging itself is covered by tests/Browser/PageEditorDragDropTest.
 */
final class EditorPaletteTest extends AppWebTestCase
{
    public function testEditorCarriesThePaletteAndTheUploadLimits(): void
    {
        $this->loginAs(UserRole::Author);
        $post = $this->createPost('Draft', $this->entity(\App\Entity\User::class, 1), status: ContentStatus::Draft, publishedAt: null);

        $crawler = $this->client->request('GET', '/admin/posts/'.$post->getId().'/edit');
        self::assertResponseIsSuccessful();

        // The palette is a list of the shared drag-and-drop layer that feeds the group of the blocks.
        $palette = $crawler->filter('[data-editor-target="palette"]');
        self::assertCount(1, $palette);
        self::assertSame('palette', $palette->attr('data-controller'));
        self::assertSame('blocks', $palette->attr('data-palette-group-value'));
        self::assertSame('palette:add->editor#paletteAdd', $palette->attr('data-action'));
        self::assertSame('Blocks', $palette->attr('aria-label'));

        $config = json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
        self::assertSame(['image/jpeg', 'image/png', 'image/gif', 'image/webp'], $config['upload']['types']);
        self::assertSame(8 * 1024 * 1024, $config['upload']['maxBytes'], 'The limit of the server, so too large files are refused before they travel.');
        self::assertSame('/admin/media/upload', $config['urls']['mediaUpload']);
        self::assertContains('gallery', array_column($config['types'], 'type'), 'Several dropped images need the gallery block.');
        foreach (['palette_add', 'palette_added', 'drop_images', 'dropped_image', 'dropped_gallery'] as $key) {
            self::assertArrayHasKey($key, $config['i18n']);
        }
    }

    public function testDroppedFilesAreCheckedByTheServerToo(): void
    {
        $this->loginAs(UserRole::Author);
        $token = $this->csrfToken('/admin/media', '[data-dropzone-csrf-value]', 'data-dropzone-csrf-value');
        $path = sys_get_temp_dir().'/symfocms-drop-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($path, '<?php echo "not an image";');

        // Whatever the browser let through: a file that is no image creates nothing.
        $this->client->request('POST', '/admin/media/upload', files: ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'photo.png', 'image/png', null, true)], server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('error', json_decode((string) $this->client->getResponse()->getContent(), true));

        // And without the token of the editor nothing is accepted at all.
        $this->client->request('POST', '/admin/media/upload', files: ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'photo.png', 'image/png', null, true)]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(\App\Entity\Media::class)->count([]));
    }
}
