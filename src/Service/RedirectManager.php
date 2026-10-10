<?php

namespace App\Service;

use App\Entity\Redirect;
use App\Repository\RedirectRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates and looks up redirects, and keeps them free of loops and chains:
 * every redirect leads straight to its final destination.
 */
final class RedirectManager
{
    public const OK = 'ok';
    public const INVALID_SOURCE = 'redirect.source_invalid';
    public const INVALID_TARGET = 'redirect.target_invalid';
    public const SAME = 'redirect.same';
    public const DUPLICATE = 'redirect.duplicate';
    public const LOOP = 'redirect.loop';

    public function __construct(
        private readonly RedirectRepository $redirects,
        private readonly EntityManagerInterface $entityManager,
        private readonly LinkSanitizer $linkSanitizer,
    ) {
    }

    /**
     * Where a request for the given path should be sent, or null.
     */
    public function match(string $path): ?string
    {
        $source = $this->normalizeSource($path);

        return null === $source ? null : $this->redirects->findOneBy(['source' => $source])?->getTarget();
    }

    /**
     * Called when the public address of a page or post changed.
     */
    public function addAutomatic(string $oldPath, string $newPath): void
    {
        $source = $this->normalizeSource($oldPath);
        $target = $this->normalizeSource($newPath);
        if (null === $source || null === $target || $source === $target) {
            return;
        }

        // The new address is live content again: a redirect away from it would hide the content.
        $this->remove($this->redirects->findOneBy(['source' => $target]));

        $redirect = $this->redirects->findOneBy(['source' => $source]) ?? (new Redirect())->setSource($source)->setAutomatic(true);
        $redirect->setTarget($target);
        $this->entityManager->persist($redirect);

        $this->retarget($source, $target);
        $this->entityManager->flush();
    }

    /**
     * Validates and stores a redirect entered by hand.
     *
     * @return string self::OK or the translation key (validators domain) of the problem
     */
    public function save(Redirect $redirect, string $source, string $target): string
    {
        $source = $this->normalizeSource($source);
        if (null === $source || '/' === $source || str_starts_with($source, '/admin')) {
            return self::INVALID_SOURCE;
        }

        $target = $this->normalizeTarget($target);
        if (null === $target) {
            return self::INVALID_TARGET;
        }
        if ($source === $target) {
            return self::SAME;
        }

        $existing = $this->redirects->findOneBy(['source' => $source]);
        if (null !== $existing && $existing->getId() !== $redirect->getId()) {
            return self::DUPLICATE;
        }

        // The destination is itself redirected: follow it to the end instead of building a chain.
        $final = $this->follow($target, $redirect);
        if (null === $final || $final === $source) {
            return self::LOOP;
        }

        $redirect->setSource($source)->setTarget($final);
        $this->entityManager->persist($redirect);
        // Redirects that used to end at this source now go to its destination directly.
        $this->retarget($source, $final, $redirect);
        $this->entityManager->flush();

        return self::OK;
    }

    /**
     * "/Old-Page/" and "old-page?x=1" both become "/old-page"-style paths; null for anything that is not a local path.
     */
    public function normalizeSource(string $path): ?string
    {
        $path = trim($path);
        if ('' === $path || preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $path)) {
            return null;
        }

        $path = '/'.ltrim((string) (parse_url($path, \PHP_URL_PATH) ?: ''), '/');
        $path = '/' === $path ? $path : rtrim($path, '/');

        return mb_strlen($path) > 255 || preg_match('/[\s<>"\x00-\x1F]/', $path) ? null : $path;
    }

    private function normalizeTarget(string $target): ?string
    {
        $target = trim($target);
        if (1 === preg_match('~^https?://~i', $target)) {
            $url = $this->linkSanitizer->sanitize($target);

            return '' === $url ? null : $url;
        }

        return $this->normalizeSource($target);
    }

    /**
     * Follows existing redirects from the given address to where they end. Null when they run in a circle.
     */
    private function follow(string $target, Redirect $ignore): ?string
    {
        $seen = [];
        while (null !== ($next = $this->redirects->findOneBy(['source' => $target])) && $next->getId() !== $ignore->getId()) {
            if (isset($seen[$target])) {
                return null;
            }
            $seen[$target] = true;
            $target = $next->getTarget();
        }

        return $target;
    }

    /**
     * Points every redirect that ends at $oldTarget to $newTarget instead.
     */
    private function retarget(string $oldTarget, string $newTarget, ?Redirect $except = null): void
    {
        foreach ($this->redirects->findBy(['target' => $oldTarget]) as $redirect) {
            if ($redirect === $except) {
                continue;
            }
            if ($redirect->getSource() === $newTarget) {
                // It would point to itself now: it has become pointless.
                $this->entityManager->remove($redirect);
            } else {
                $redirect->setTarget($newTarget);
            }
        }
    }

    private function remove(?Redirect $redirect): void
    {
        if (null !== $redirect) {
            $this->entityManager->remove($redirect);
            $this->entityManager->flush();
        }
    }
}
