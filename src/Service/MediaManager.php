<?php

namespace App\Service;

use App\Entity\Media;
use App\Entity\User;
use App\Exception\InvalidUploadException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores uploaded images, creates resized versions and removes them again.
 */
final class MediaManager
{
    /** Allowed MIME types and the file extension they are stored with. SVG is excluded on purpose: it can carry scripts. */
    public const MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Variant name => maximum width in pixels. */
    public const VARIANTS = [
        'thumb' => 400,
        'medium' => 900,
        'large' => 1800,
    ];

    /** A version cut to the shape social networks show, around the focal point of the image. */
    public const SOCIAL = 'social';
    private const SOCIAL_SIZE = [1200, 630];

    /** Protects the server from "decompression bombs": tiny files that unpack to enormous images. */
    private const MAX_PIXELS = 40_000_000;

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'app.media_dir')]
        private readonly string $mediaDir,
        #[Autowire(param: 'app.max_upload_mb')]
        private readonly int $maxUploadMb,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Largest accepted file in megabytes: the configured limit, or less when PHP itself
     * (upload_max_filesize / post_max_size) does not allow that much.
     */
    public function maxUploadMb(): int
    {
        return max(1, min($this->maxUploadMb, (int) floor(UploadedFile::getMaxFilesize() / 1024 / 1024)));
    }

    /**
     * @throws InvalidUploadException with a translation key (validators domain) when the file is not acceptable
     */
    public function upload(UploadedFile $file, ?User $uploadedBy = null): Media
    {
        $limit = $this->maxUploadMb();

        if (!$file->isValid()) {
            throw new InvalidUploadException(\UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError() ? 'media.too_large' : 'media.upload_failed', ['%limit%' => $limit]);
        }

        if ($file->getSize() > $limit * 1024 * 1024) {
            throw new InvalidUploadException('media.too_large', ['%limit%' => $limit]);
        }

        // Never trust the client: look at the file content, not at its name or declared type.
        $mimeType = (string) $file->getMimeType();
        $size = @getimagesize($file->getPathname());
        if (!isset(self::MIME_TYPES[$mimeType]) || false === $size || $size['mime'] !== $mimeType) {
            throw new InvalidUploadException('media.invalid_type');
        }

        [$width, $height] = $size;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new InvalidUploadException('media.too_many_pixels');
        }

        $baseName = bin2hex(random_bytes(12));
        $extension = self::MIME_TYPES[$mimeType];
        $filename = $baseName.'.'.$extension;
        $originalName = $file->getClientOriginalName();
        $fileSize = (int) $file->getSize();

        $this->filesystem->mkdir($this->mediaDir);
        $file->move($this->mediaDir, $filename);
        $path = $this->mediaDir.'/'.$filename;

        $orientation = 'image/jpeg' === $mimeType ? $this->readOrientation($path) : 1;
        if ($orientation >= 5) {
            // The camera was held sideways: browsers rotate the image, so width and height swap.
            [$width, $height] = [$height, $width];
        }

        $media = (new Media())
            ->setFilename($filename)
            ->setOriginalName($originalName)
            ->setMimeType($mimeType)
            ->setSize($fileSize)
            ->setDimensions($width, $height)
            ->setAlt('')
            ->setVariants($this->createVariants($path, $baseName, $mimeType, $orientation))
            ->setUploadedBy($uploadedBy);

        $this->entityManager->persist($media);
        $this->entityManager->flush();

        return $media;
    }

    /**
     * Rotates and/or crops an image. The upload as it was is kept, so restore() can undo everything.
     *
     * @param int                                                $rotate degrees clockwise: 0, 90, 180 or 270
     * @param array{x: float, y: float, w: float, h: float}|null $crop   part to keep, as fractions (0-1) of the rotated image
     *
     * @throws InvalidUploadException when the image cannot be processed
     */
    public function transform(Media $media, int $rotate, ?array $crop): void
    {
        $path = $this->mediaDir.'/'.$media->getFilename();
        $mimeType = $media->getMimeType();
        $image = $media->isAnimated() ? false : $this->load($path, $mimeType);
        if (false === $image) {
            throw new InvalidUploadException('media.edit_failed');
        }

        // Photos carry their orientation as a note in the file; the edited image has it applied to its pixels.
        $image = $this->applyOrientation($image, 'image/jpeg' === $mimeType ? $this->readOrientation($path) : 1);

        $rotate = (($rotate % 360) + 360) % 360;
        if (\in_array($rotate, [90, 180, 270], true)) {
            // GD turns counter-clockwise.
            $rotated = imagerotate($image, 360 - $rotate, (int) imagecolorallocatealpha($image, 255, 255, 255, 127));
            $image = false === $rotated ? $image : $rotated;
        }

        if (null !== $crop) {
            $width = imagesx($image);
            $height = imagesy($image);
            $x = (int) round(max(0.0, min(1.0, $crop['x'])) * $width);
            $y = (int) round(max(0.0, min(1.0, $crop['y'])) * $height);
            $rect = [
                'x' => min($x, $width - 1),
                'y' => min($y, $height - 1),
                'width' => max(1, min((int) round($crop['w'] * $width), $width - $x)),
                'height' => max(1, min((int) round($crop['h'] * $height), $height - $y)),
            ];
            // A crop of a few pixels is a slip of the hand, not an intention.
            if ($rect['width'] < 16 || $rect['height'] < 16) {
                throw new InvalidUploadException('media.crop_too_small');
            }

            $cropped = imagecrop($image, $rect);
            $image = false === $cropped ? $image : $cropped;
        }

        // A new file name, so browsers and caches do not keep showing the old picture.
        $baseName = bin2hex(random_bytes(12));
        $filename = $baseName.'.'.self::MIME_TYPES[$mimeType];
        imagealphablending($image, false);
        imagesavealpha($image, true);
        if (!$this->write($image, $this->mediaDir.'/'.$filename, $mimeType)) {
            throw new InvalidUploadException('media.edit_failed');
        }

        $obsolete = array_column($media->getVariants(), 'file');
        if (null === $media->getSourceFile()) {
            $media->setSourceFile($media->getFilename());
        } else {
            $obsolete[] = $media->getFilename();
        }

        $media
            ->setFilename($filename)
            ->setSize((int) filesize($this->mediaDir.'/'.$filename))
            ->setDimensions(imagesx($image), imagesy($image))
            // The old focal point may not even be part of the picture any more.
            ->setFocalPoint(0.5, 0.5)
            ->setVariants($this->createVariants($this->mediaDir.'/'.$filename, $baseName, $mimeType, 1));
        $this->entityManager->flush();

        $this->removeFiles($obsolete);
    }

    /**
     * Brings back the image as it was uploaded.
     */
    public function restore(Media $media): void
    {
        $source = $media->getSourceFile();
        if (null === $source || !is_file($this->mediaDir.'/'.$source)) {
            return;
        }

        $obsolete = [$media->getFilename(), ...array_column($media->getVariants(), 'file')];
        $path = $this->mediaDir.'/'.$source;
        $mimeType = $media->getMimeType();
        $size = getimagesize($path);
        [$width, $height] = false !== $size ? $size : [$media->getWidth(), $media->getHeight()];
        $orientation = 'image/jpeg' === $mimeType ? $this->readOrientation($path) : 1;
        if ($orientation >= 5) {
            [$width, $height] = [$height, $width];
        }

        $media
            ->setFilename($source)
            ->setSourceFile(null)
            ->setSize((int) filesize($path))
            ->setDimensions($width, $height)
            ->setFocalPoint(0.5, 0.5)
            ->setVariants($this->createVariants($path, pathinfo($source, \PATHINFO_FILENAME), $mimeType, $orientation));
        $this->entityManager->flush();

        $this->removeFiles($obsolete);
    }

    /**
     * Stores the focal point and cuts the version for social networks around it again.
     */
    public function setFocalPoint(Media $media, float $x, float $y): void
    {
        $media->setFocalPoint($x, $y);

        $path = $this->mediaDir.'/'.$media->getFilename();
        $mimeType = $media->getMimeType();
        $image = $media->isAnimated() ? false : $this->load($path, $mimeType);
        if (false !== $image) {
            $image = $this->applyOrientation($image, 'image/jpeg' === $mimeType ? $this->readOrientation($path) : 1);
            $variants = $media->getVariants();
            $old = $variants[self::SOCIAL]['file'] ?? null;
            unset($variants[self::SOCIAL]);

            // A new name again, for the same reason as in transform().
            $social = $this->createSocialVariant($image, bin2hex(random_bytes(12)), $mimeType, $media->getFocalX(), $media->getFocalY());
            if (null !== $social) {
                $variants[self::SOCIAL] = $social;
            }
            $media->setVariants($variants);
            $this->entityManager->flush();
            $this->removeFiles(null !== $old ? [$old] : []);

            return;
        }

        $this->entityManager->flush();
    }

    public function delete(Media $media): void
    {
        $files = array_filter([$media->getFilename(), $media->getSourceFile(), ...array_column($media->getVariants(), 'file')]);

        $this->entityManager->remove($media);
        $this->entityManager->flush();

        $this->removeFiles($files);
    }

    /**
     * @param array<array-key, string> $files
     */
    private function removeFiles(array $files): void
    {
        foreach ($files as $file) {
            // File names are generated by this class, but stay defensive about what gets deleted.
            if (preg_match('/^[a-f0-9]+(-[a-z]+)?\.[a-z]+$/', $file)) {
                $this->filesystem->remove($this->mediaDir.'/'.$file);
            }
        }
    }

    private function load(string $path, string $mimeType): \GdImage|false
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function write(\GdImage $image, string $target, string $mimeType): bool
    {
        return match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $target, 82),
            'image/png' => imagepng($image, $target, 7),
            'image/gif' => imagegif($image, $target),
            'image/webp' => imagewebp($image, $target, 82),
            default => false,
        };
    }

    /**
     * Cuts the image to 1200 x 630 pixels, keeping the focal point as close to the middle as the edges allow.
     *
     * @return array{file: string, width: int, height: int}|null null for images that are too small to be cut that way
     */
    private function createSocialVariant(\GdImage $source, string $baseName, string $mimeType, float $focalX, float $focalY): ?array
    {
        [$width, $height] = self::SOCIAL_SIZE;
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < $width || $sourceHeight < $height) {
            return null;
        }

        // The largest area of the wanted shape that fits into the image ...
        $scale = min($sourceWidth / $width, $sourceHeight / $height);
        $areaWidth = (int) round($width * $scale);
        $areaHeight = (int) round($height * $scale);
        // ... placed around the focal point, without leaving the image.
        $x = (int) max(0, min($sourceWidth - $areaWidth, round($focalX * $sourceWidth - $areaWidth / 2)));
        $y = (int) max(0, min($sourceHeight - $areaHeight, round($focalY * $sourceHeight - $areaHeight / 2)));

        $target = imagecreatetruecolor($width, $height);
        if (false === $target) {
            return null;
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 255, 255, 255, 127));
        imagecopyresampled($target, $source, 0, 0, $x, $y, $width, $height, $areaWidth, $areaHeight);

        $file = $baseName.'-'.self::SOCIAL.'.'.self::MIME_TYPES[$mimeType];

        return $this->write($target, $this->mediaDir.'/'.$file, $mimeType) ? ['file' => $file, 'width' => $width, 'height' => $height] : null;
    }

    /**
     * @return array<string, array{file: string, width: int, height: int}>
     */
    private function createVariants(string $path, string $baseName, string $mimeType, int $orientation): array
    {
        $source = $this->load($path, $mimeType);

        // Without a readable image there are simply no variants: the original is used everywhere.
        if (false === $source) {
            return [];
        }

        $source = $this->applyOrientation($source, $orientation);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $extension = self::MIME_TYPES[$mimeType];
        $variants = [];

        foreach (self::VARIANTS as $name => $maxWidth) {
            // Never enlarge. Animated GIFs only get a (static) thumbnail so the animation survives elsewhere.
            if ($sourceWidth <= $maxWidth || ('image/gif' === $mimeType && 'thumb' !== $name)) {
                continue;
            }

            $width = $maxWidth;
            $height = max(1, (int) round($sourceHeight * $maxWidth / $sourceWidth));
            $resized = imagecreatetruecolor($width, $height);
            if (false === $resized) {
                continue;
            }

            // Keep transparency for PNG, GIF and WebP.
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, (int) imagecolorallocatealpha($resized, 255, 255, 255, 127));
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

            $file = $baseName.'-'.$name.'.'.$extension;
            $target = $this->mediaDir.'/'.$file;
            if ($this->write($resized, $target, $mimeType)) {
                $variants[$name] = ['file' => $file, 'width' => $width, 'height' => $height];
            }
        }

        if ('image/gif' !== $mimeType && null !== $social = $this->createSocialVariant($source, $baseName, $mimeType, 0.5, 0.5)) {
            $variants[self::SOCIAL] = $social;
        }

        return $variants;
    }

    private function readOrientation(string $path): int
    {
        if (!\function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);
        $orientation = \is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        $image = false === $rotated ? $image : $rotated;

        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, \IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }
}
