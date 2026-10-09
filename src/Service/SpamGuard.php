<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Spam protection for public forms that needs no third-party service and no
 * puzzle for the visitor:
 *
 *  - a honeypot: a field people never see, but bots fill in;
 *  - a minimum fill time: people need a few seconds, bots answer instantly.
 *
 * The third measure, rate limiting, lives in FormSubmitter.
 */
final class SpamGuard
{
    /** Name of the honeypot field. It looks like a field bots are eager to fill. */
    public const HONEYPOT = 'website';
    public const TIMESTAMP = '_started';

    public const OK = 'ok';
    /** Certainly a bot: pretend everything worked and store nothing. */
    public const BOT = 'bot';
    /** Sent implausibly fast or with a stale page: ask to try again. */
    public const TOO_FAST = 'too_fast';

    private const MIN_SECONDS = 3;
    private const MAX_SECONDS = 86400;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * Value for the hidden field that records when the form was shown.
     * It is signed, so a bot cannot simply invent an old timestamp.
     */
    public function createTimestamp(?int $now = null): string
    {
        $time = (string) ($now ?? time());

        return $time.'.'.$this->sign($time);
    }

    public function check(Request $request, ?int $now = null): string
    {
        if ('' !== trim($request->request->getString(self::HONEYPOT))) {
            return self::BOT;
        }

        [$time, $signature] = array_pad(explode('.', $request->request->getString(self::TIMESTAMP), 2), 2, '');
        if (!ctype_digit($time) || !hash_equals($this->sign($time), $signature)) {
            return self::TOO_FAST;
        }

        $elapsed = ($now ?? time()) - (int) $time;

        return $elapsed >= self::MIN_SECONDS && $elapsed <= self::MAX_SECONDS ? self::OK : self::TOO_FAST;
    }

    private function sign(string $time): string
    {
        return substr(hash_hmac('sha256', 'form-started-'.$time, $this->secret), 0, 32);
    }
}
