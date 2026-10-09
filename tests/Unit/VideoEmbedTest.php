<?php

namespace App\Tests\Unit;

use App\Service\VideoEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoEmbedTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function links(): iterable
    {
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0'];
        yield 'youtube short link' => ['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0'];
        yield 'youtube shorts' => ['https://youtube.com/shorts/abcDEF12345', 'https://www.youtube-nocookie.com/embed/abcDEF12345?autoplay=1&rel=0'];
        yield 'youtube mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0'];
        yield 'vimeo' => ['https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789?autoplay=1&dnt=1'];
        yield 'vimeo player' => ['https://player.vimeo.com/video/123456789?h=abc', 'https://player.vimeo.com/video/123456789?autoplay=1&dnt=1'];
        yield 'other website' => ['https://example.com/watch?v=dQw4w9WgXcQ', null];
        yield 'lookalike host' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ', null];
        yield 'injection attempt' => ['https://www.youtube.com/watch?v="><script>', null];
        yield 'not a link' => ['hello', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('links')]
    public function testParse(string $url, ?string $expectedEmbedUrl): void
    {
        $video = (new VideoEmbed())->parse($url);

        self::assertSame($expectedEmbedUrl, $video['embedUrl'] ?? null);
    }
}
