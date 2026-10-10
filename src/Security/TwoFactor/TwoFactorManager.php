<?php

namespace App\Security\TwoFactor;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\Settings;
use App\Service\SiteInfo;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Two-step login: after the password, a code from an authenticator app.
 *
 * Every user can switch it on for their account. Backup codes get people in
 * when the phone is gone; each of them works once.
 */
final class TwoFactorManager
{
    public const REQUIRED_FOR_ADMINS_SETTING = 'security.two_factor_admins';
    private const VERIFIED_KEY = '_two_factor_verified';
    private const PENDING_SECRET_KEY = '_two_factor_pending_secret';
    private const BACKUP_CODES = 10;
    /** Letters and digits that are hard to mix up when read from paper. */
    private const BACKUP_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(
        private readonly Totp $totp,
        private readonly EntityManagerInterface $entityManager,
        private readonly Settings $settings,
        private readonly SiteInfo $site,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {
    }

    /**
     * True when the website asks this user to use two-step login but they have not set it up yet.
     */
    public function mustSetUp(User $user): bool
    {
        return !$user->isTwoFactorEnabled() && UserRole::Admin === $user->getRole() && $this->isRequiredForAdmins();
    }

    public function isRequiredForAdmins(): bool
    {
        return true === $this->settings->get(self::REQUIRED_FOR_ADMINS_SETTING);
    }

    public function requireForAdmins(bool $required): void
    {
        $this->settings->set(self::REQUIRED_FOR_ADMINS_SETTING, $required ? true : null);
    }

    /**
     * Has this browser session already passed the second step for the user's current secret?
     */
    public function isVerified(User $user, SessionInterface $session): bool
    {
        return \is_string($session->get(self::VERIFIED_KEY)) && hash_equals($this->fingerprint($user), $session->get(self::VERIFIED_KEY));
    }

    public function markVerified(User $user, SessionInterface $session): void
    {
        $session->set(self::VERIFIED_KEY, $this->fingerprint($user));
    }

    /**
     * Starts the setup: the secret is kept in the session until a first code proves that the app knows it.
     */
    public function pendingSecret(SessionInterface $session, bool $renew = false): string
    {
        $secret = $session->get(self::PENDING_SECRET_KEY);
        if ($renew || !\is_string($secret)) {
            $secret = $this->totp->generateSecret();
            $session->set(self::PENDING_SECRET_KEY, $secret);
        }

        return $secret;
    }

    /**
     * Finishes the setup with a code from the app.
     *
     * @return list<string>|null the backup codes, shown to the user exactly once; null when the code is wrong
     */
    public function enable(User $user, string $code, SessionInterface $session): ?array
    {
        $secret = $session->get(self::PENDING_SECRET_KEY);
        $step = \is_string($secret) ? $this->totp->verify($secret, $code) : null;
        if (!\is_string($secret) || null === $step) {
            return null;
        }

        $user->setTotpSecret($secret)->setTotpLastStep($step);
        $codes = $this->newBackupCodes($user);
        $this->entityManager->flush();

        $session->remove(self::PENDING_SECRET_KEY);
        $this->markVerified($user, $session);

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->setTotpSecret(null)->setTotpLastStep(null)->setBackupCodes([]);
        $this->entityManager->flush();
    }

    /**
     * Replaces the backup codes; the old ones stop working.
     *
     * @return list<string>
     */
    public function regenerateBackupCodes(User $user): array
    {
        $codes = $this->newBackupCodes($user);
        $this->entityManager->flush();

        return $codes;
    }

    /**
     * Checks what the user typed in the second step: a code from the app or a backup code.
     */
    public function verify(User $user, string $input): bool
    {
        $secret = $user->getTotpSecret();
        if (null === $secret) {
            return false;
        }

        $step = $this->totp->verify($secret, $input);
        if (null !== $step) {
            // A code that was used already, or an older one, is not accepted again.
            if ($step <= ($user->getTotpLastStep() ?? 0)) {
                return false;
            }

            $user->setTotpLastStep($step);
            $this->entityManager->flush();

            return true;
        }

        $hash = $this->hashBackupCode($input);
        $remaining = array_values(array_filter($user->getBackupCodes(), static fn (string $stored): bool => !hash_equals($stored, $hash)));
        if (\count($remaining) === \count($user->getBackupCodes())) {
            return false;
        }

        $user->setBackupCodes($remaining);
        $this->entityManager->flush();

        return true;
    }

    /**
     * The QR code for the authenticator app as an image address that can be put into <img src>.
     */
    public function qrCode(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 2), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($this->totp->provisioningUri($secret, $user->getEmail(), $this->site->name())));
    }

    /**
     * @return list<string>
     */
    private function newBackupCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODES; ++$i) {
            $code = '';
            for ($j = 0; $j < 10; ++$j) {
                $code .= self::BACKUP_ALPHABET[random_int(0, \strlen(self::BACKUP_ALPHABET) - 1)];
            }
            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
        }

        // Only fingerprints are stored: the codes cannot be read from the database.
        $user->setBackupCodes(array_map($this->hashBackupCode(...), $codes));

        return $codes;
    }

    private function hashBackupCode(string $code): string
    {
        return hash_hmac('sha256', (string) preg_replace('/[^a-z0-9]/', '', strtolower($code)), $this->secret);
    }

    /**
     * Changes whenever the secret changes, so a reset invalidates every session that passed the old second step.
     */
    private function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->getId().'|'.$user->getTotpSecret(), $this->secret);
    }
}
