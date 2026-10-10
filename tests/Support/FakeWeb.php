<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for the internet in tests: every request of the HTTP client is
 * answered here (see "mock_response_factory" in config/packages/framework.yaml).
 *
 * Addresses ending in an image extension return a small PNG, unless they
 * contain "missing" (404) or "broken" (not an image).
 */
final class FakeWeb
{
    /** @var list<string> every address that was requested, for assertions */
    public static array $requests = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$requests[] = $url;

        if (str_contains($url, 'missing') || 1 !== preg_match('~\.(png|jpe?g|gif|webp)(\?.*)?$~i', $url)) {
            return new MockResponse('Not found', ['http_code' => 404]);
        }
        if (str_contains($url, 'broken')) {
            return new MockResponse('<html>This is not an image</html>', ['http_code' => 200]);
        }

        return new MockResponse(self::png(), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'image/png']]);
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(640, 480);
        \assert(false !== $image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
