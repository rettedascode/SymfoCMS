<?php

namespace App\Service;

/**
 * Recognises links to videos on YouTube and Vimeo and knows how to embed them.
 */
final class VideoEmbed
{
    /**
     * @return array{provider: string, name: string, embedUrl: string, watchUrl: string}|null null when the link is not a supported video
     */
    public function parse(mixed $url): ?array
    {
        if (!\is_string($url) || '' === $url) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower(preg_replace('/^(www|m)\./', '', $parts['host'] ?? '') ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        $youtubeId = match (true) {
            'youtu.be' === $host => ltrim($path, '/'),
            \in_array($host, ['youtube.com', 'youtube-nocookie.com', 'music.youtube.com'], true) && '/watch' === $path => \is_string($query['v'] ?? null) ? $query['v'] : '',
            \in_array($host, ['youtube.com', 'youtube-nocookie.com'], true) && 1 === preg_match('~^/(?:embed|shorts|live)/([^/]+)~', $path, $match) => $match[1],
            default => '',
        };

        if (1 === preg_match('/^[A-Za-z0-9_-]{6,20}$/', $youtubeId)) {
            return [
                'provider' => 'youtube',
                'name' => 'YouTube',
                // The "nocookie" domain sets no cookies before the video is played.
                'embedUrl' => 'https://www.youtube-nocookie.com/embed/'.$youtubeId.'?autoplay=1&rel=0',
                'watchUrl' => 'https://www.youtube.com/watch?v='.$youtubeId,
            ];
        }

        if (\in_array($host, ['vimeo.com', 'player.vimeo.com'], true) && 1 === preg_match('~/(\d{5,12})(?:$|[/?#])~', $path.'/', $match)) {
            return [
                'provider' => 'vimeo',
                'name' => 'Vimeo',
                'embedUrl' => 'https://player.vimeo.com/video/'.$match[1].'?autoplay=1&dnt=1',
                'watchUrl' => 'https://vimeo.com/'.$match[1],
            ];
        }

        return null;
    }
}
