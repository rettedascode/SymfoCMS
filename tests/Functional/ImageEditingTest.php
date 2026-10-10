<?php

namespace App\Tests\Functional;

use App\Entity\Media;
use App\Entity\Page;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Service\MediaManager;
use App\Tests\AppWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageEditingTest extends AppWebTestCase
{
    public function testImageIsRotatedCroppedAndRestored(): void
    {
        $this->loginAs(UserRole::Editor);
        $media = $this->upload(1600, 1000);
        $id = $media->getId();
        $uploaded = $media->getFilename();
        $uploadedThumb = $media->getVariants()['thumb']['file'];
        self::assertSame(['thumb', 'medium', 'social'], array_keys($media->getVariants()));

        $crawler = $this->client->request('GET', '/admin/media/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="image-editor"]');
        self::assertCount(0, $crawler->filter('form[action$="/restore"]'), 'Nothing to undo yet.');

        // A quarter turn to the right swaps width and height.
        $this->client->submit($crawler->selectButton('↻ Rotate right')->form());
        self::assertResponseRedirects('/admin/media/'.$id.'/edit');
        $media = $this->media($id);
        self::assertSame([1000, 1600], [$media->getWidth(), $media->getHeight()]);
        self::assertNotSame($uploaded, $media->getFilename(), 'A new file name keeps browsers from showing the old picture.');
        self::assertSame($uploaded, $media->getSourceFile());
        self::assertFileExists($this->mediaDir().'/'.$uploaded, 'The upload is kept for undoing.');
        self::assertFileDoesNotExist($this->mediaDir().'/'.$uploadedThumb);
        self::assertSame([1000, 1600], \array_slice((array) getimagesize($this->mediaDir().'/'.$media->getFilename()), 0, 2));
        foreach ($media->getVariants() as $variant) {
            self::assertFileExists($this->mediaDir().'/'.$variant['file']);
        }
        self::assertSame(400, $media->getVariants()['thumb']['width']);
        self::assertSame(640, $media->getVariants()['thumb']['height']);

        // Keep the middle half.
        $rotated = $media->getFilename();
        $crawler = $this->client->request('GET', '/admin/media/'.$id.'/edit');
        $this->client->submit($crawler->selectButton('Crop image')->form(['x' => '0.25', 'y' => '0.25', 'w' => '0.5', 'h' => '0.5']));
        self::assertResponseRedirects('/admin/media/'.$id.'/edit');
        $media = $this->media($id);
        self::assertSame([500, 800], [$media->getWidth(), $media->getHeight()]);
        self::assertSame($uploaded, $media->getSourceFile(), 'Still the very first upload.');
        self::assertFileDoesNotExist($this->mediaDir().'/'.$rotated, 'Steps in between are not kept.');
        self::assertSame(['thumb'], array_keys($media->getVariants()), 'Too small now for the larger versions.');
        self::assertSame((int) filesize($this->mediaDir().'/'.$media->getFilename()), $media->getSize());

        // A crop of a few pixels is refused and changes nothing.
        $crawler = $this->client->request('GET', '/admin/media/'.$id.'/edit');
        $this->client->submit($crawler->selectButton('Crop image')->form(['x' => '0', 'y' => '0', 'w' => '0.01', 'h' => '0.01']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'too small');
        self::assertSame($media->getFilename(), $this->media($id)->getFilename());

        // Undo everything.
        $cropped = $media->getFilename();
        $crawler = $this->client->request('GET', '/admin/media/'.$id.'/edit');
        $this->client->submit($crawler->filter('form[action$="/restore"]')->form());
        $media = $this->media($id);
        self::assertSame($uploaded, $media->getFilename());
        self::assertNull($media->getSourceFile());
        self::assertSame([1600, 1000], [$media->getWidth(), $media->getHeight()]);
        self::assertSame(['thumb', 'medium', 'social'], array_keys($media->getVariants()));
        self::assertFileDoesNotExist($this->mediaDir().'/'.$cropped);
        self::assertFileExists($this->mediaDir().'/'.$media->getVariants()['medium']['file']);

        // Deleting an edited image removes the kept upload as well.
        $this->client->request('POST', '/admin/media/'.$id.'/rotate', ['_token' => $this->csrfToken('/admin/media/'.$id.'/edit'), 'direction' => 'left']);
        $media = $this->media($id);
        self::assertSame([1000, 1600], [$media->getWidth(), $media->getHeight()]);
        $this->client->request('POST', '/admin/media/'.$id.'/delete', ['_token' => $this->csrfToken('/admin/media/'.$id.'/edit')]);
        self::assertSame([], glob($this->mediaDir().'/*') ?: []);
    }

    public function testFocalPointDecidesWhatStaysVisible(): void
    {
        $this->loginAs(UserRole::Editor);
        // A tall image: red at the top, blue at the bottom.
        $media = $this->upload(1300, 2000, split: true);
        $id = $media->getId();
        self::assertSame([0.5, 0.5], [$media->getFocalX(), $media->getFocalY()]);
        $centered = $media->getVariants()['social']['file'];

        // Clicking on the image sends the point as JSON.
        $token = $this->csrfToken('/admin/media/'.$id.'/edit');
        $this->client->request('POST', '/admin/media/'.$id.'/focus', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $this->json(['x' => 0.5, 'y' => 0.1]));
        self::assertResponseIsSuccessful();
        $media = $this->media($id);
        self::assertSame([0.5, 0.1], [$media->getFocalX(), $media->getFocalY()]);

        // The version for social networks is cut around the point: now from the red part ...
        $social = $media->getVariants()['social'];
        self::assertSame([1200, 630], [$social['width'], $social['height']]);
        self::assertNotSame($centered, $social['file']);
        self::assertFileDoesNotExist($this->mediaDir().'/'.$centered);
        self::assertSame('red', $this->colorAtCenter($social['file']));

        // ... and after moving the point down, from the blue part. Without JavaScript the form does the same.
        $this->client->request('POST', '/admin/media/'.$id.'/focus', ['_token' => $token, 'x' => '0.5', 'y' => '0.9']);
        self::assertResponseRedirects('/admin/media/'.$id.'/edit');
        $media = $this->media($id);
        self::assertSame('blue', $this->colorAtCenter($media->getVariants()['social']['file']));

        // On the website, images that are cut by the layout keep the point in view.
        $page = $this->createPage('Gallery', ContentStatus::Published);
        $page->setShareImage($media)->setBlocks([['type' => 'gallery', 'data' => ['images' => [$id], 'columns' => '3']]]);
        $this->em()->flush();
        $crawler = $this->client->request('GET', '/gallery');
        $image = $crawler->filter('.block-gallery img');
        self::assertSame('object-position: 50% 90%', $image->attr('style'));
        self::assertStringNotContainsString('social', (string) $image->attr('srcset'), 'The cut version is not offered as a smaller copy.');
        self::assertStringContainsString($media->getVariants()['social']['file'], (string) $crawler->filter('meta[property="og:image"]')->attr('content'));

        // Centred images need no extra attribute.
        $plain = $this->upload(1600, 1000);
        $this->entity(Page::class, $page->getId())->setBlocks([['type' => 'gallery', 'data' => ['images' => [$plain->getId()], 'columns' => '3']]]);
        $this->em()->flush();
        self::assertNull($this->client->request('GET', '/gallery')->filter('.block-gallery img')->attr('style'));

        // Nonsense is refused.
        foreach ([['x' => 2, 'y' => 0.5], ['x' => 'left', 'y' => 0.5], ['x' => 0.5]] as $payload) {
            $this->client->request('POST', '/admin/media/'.$id.'/focus', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $this->json($payload));
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('POST', '/admin/media/'.$id.'/crop', ['_token' => $token, 'x' => '0', 'y' => '0', 'w' => '5', 'h' => '1']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testOnlyPeopleWhoMayEditAnImageChangeIt(): void
    {
        $editor = $this->createUser(UserRole::Editor);
        $author = $this->loginAs(UserRole::Author);
        $foreign = $this->upload(1600, 1000, $editor);
        $own = $this->upload(1600, 1000, $author);
        $before = $foreign->getFilename();

        // Somebody else's image: visible, but without the tools.
        $crawler = $this->client->request('GET', '/admin/media/'.$foreign->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-controller="image-editor"]');
        // The token of the own image is valid for every admin form of this session.
        $token = $this->csrfToken('/admin/media/'.$own->getId().'/edit');

        foreach (['rotate' => ['direction' => 'left'], 'crop' => ['x' => '0', 'y' => '0', 'w' => '0.5', 'h' => '0.5'], 'restore' => [], 'focus' => ['x' => '0.2', 'y' => '0.2']] as $action => $data) {
            $this->client->request('POST', '/admin/media/'.$foreign->getId().'/'.$action, ['_token' => $token] + $data);
            self::assertResponseStatusCodeSame(403, $action);
        }
        $foreign = $this->media($foreign->getId());
        self::assertSame($before, $foreign->getFilename());
        self::assertSame(0.5, $foreign->getFocalX());

        // The own upload can be edited, but not without the form token.
        $this->client->request('POST', '/admin/media/'.$own->getId().'/rotate', ['direction' => 'left']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/media/'.$own->getId().'/rotate', ['_token' => $token, 'direction' => 'left']);
        self::assertResponseRedirects();
        self::assertSame(1000, $this->media($own->getId())->getWidth());
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function upload(int $width, int $height, ?User $by = null, bool $split = false): Media
    {
        $path = sys_get_temp_dir().'/symfocms-edit-'.bin2hex(random_bytes(4)).'.png';
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 220, 30, 30));
        if ($split) {
            imagefilledrectangle($image, 0, intdiv($height, 2), $width, $height, (int) imagecolorallocate($image, 30, 30, 220));
        }
        imagepng($image, $path);

        return static::getContainer()->get(MediaManager::class)->upload(new UploadedFile($path, 'photo.png', 'image/png', null, true), $by);
    }

    private function media(?int $id): Media
    {
        $this->em()->clear();

        return $this->entity(Media::class, $id);
    }

    private function colorAtCenter(string $file): string
    {
        $image = imagecreatefrompng($this->mediaDir().'/'.$file);
        self::assertNotFalse($image);
        $color = (int) imagecolorat($image, intdiv(imagesx($image), 2), intdiv(imagesy($image), 2));

        return (($color >> 16) & 0xFF) > ($color & 0xFF) ? 'red' : 'blue';
    }
}
