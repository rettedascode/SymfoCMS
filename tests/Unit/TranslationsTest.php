<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * English is the reference language: every other language must offer exactly the same texts.
 */
final class TranslationsTest extends TestCase
{
    private const DIRECTORY = __DIR__.'/../../translations';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function translationFiles(): iterable
    {
        foreach ((array) glob(self::DIRECTORY.'/*.*.yaml') as $file) {
            [$domain, $locale] = explode('.', basename((string) $file));
            if ('en' !== $locale) {
                yield $domain.'.'.$locale => [$domain, $locale];
            }
        }
    }

    #[DataProvider('translationFiles')]
    public function testTranslationIsComplete(string $domain, string $locale): void
    {
        $reference = $this->load($domain, 'en');
        $translation = $this->load($domain, $locale);

        self::assertSame([], array_values(array_diff(array_keys($reference), array_keys($translation))), \sprintf('Keys missing in %s.%s.yaml', $domain, $locale));
        self::assertSame([], array_values(array_diff(array_keys($translation), array_keys($reference))), \sprintf('Keys in %s.%s.yaml that do not exist in English', $domain, $locale));

        foreach ($reference as $key => $message) {
            self::assertNotSame('', trim($translation[$key]), \sprintf('Empty translation for "%s"', $key));

            // Placeholders such as %name% must survive the translation.
            preg_match_all('/%[a-z_]+%/', $message, $expected);
            preg_match_all('/%[a-z_]+%/', $translation[$key], $actual);
            sort($expected[0]);
            sort($actual[0]);
            self::assertSame($expected[0], $actual[0], \sprintf('Placeholders differ for "%s" in %s.%s.yaml', $key, $domain, $locale));
        }
    }

    /**
     * @return array<string, string>
     */
    private function load(string $domain, string $locale): array
    {
        $messages = Yaml::parseFile(\sprintf('%s/%s.%s.yaml', self::DIRECTORY, $domain, $locale));
        self::assertIsArray($messages);

        return array_map(strval(...), $messages);
    }
}
