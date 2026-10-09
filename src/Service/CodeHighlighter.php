<?php

namespace App\Service;

/**
 * Small server-side syntax highlighter for the code block.
 *
 * It marks comments, strings, numbers and keywords - enough to make code
 * pleasant to read - and needs no JavaScript on the website. The result is
 * HTML in which everything that came from the user is escaped.
 */
final class CodeHighlighter
{
    /** Language key => label shown in the editor. */
    public const LANGUAGES = [
        'plain' => 'block.code.language_plain',
        'html' => 'HTML',
        'css' => 'CSS',
        'javascript' => 'JavaScript',
        'php' => 'PHP',
        'python' => 'Python',
        'json' => 'JSON',
        'bash' => 'Shell',
        'sql' => 'SQL',
        'yaml' => 'YAML',
        'twig' => 'Twig',
    ];

    private const KEYWORDS = [
        'javascript' => 'async await break case catch class const continue default delete do else export extends false finally for from function if import in instanceof let new null of return static super switch this throw true try typeof undefined var void while yield',
        'php' => 'abstract array as bool break case catch class const continue declare default do echo else elseif enum extends false final finally float fn for foreach function if implements instanceof int interface match namespace new null private protected public readonly return self static string switch throw trait true try use void while yield',
        'python' => 'and as assert async await break class continue def del elif else except False finally for from global if import in is lambda None nonlocal not or pass raise return True try while with yield',
        'bash' => 'case do done echo elif else esac exit export fi for function if in local return then until while',
        'sql' => 'add alter and as asc between by case create delete desc distinct drop else end exists from group having in index inner insert into is join key left like limit not null on or order outer primary right select set table then union unique update values when where',
        'json' => 'true false null',
        'yaml' => 'true false null yes no',
        'css' => 'important',
    ];

    /** Line comment markers per language. */
    private const LINE_COMMENTS = [
        'javascript' => ['//'],
        'php' => ['//', '#'],
        'python' => ['#'],
        'bash' => ['#'],
        'sql' => ['--'],
        'yaml' => ['#'],
    ];

    /** Languages with block comments in the C style. */
    private const BLOCK_COMMENTS = ['javascript', 'php', 'css', 'sql'];

    public function highlight(string $code, string $language): string
    {
        return match (true) {
            'html' === $language => $this->highlightMarkup($code, '<!--', '-->'),
            'twig' === $language => $this->highlightMarkup($code, '{#', '#}'),
            isset(self::KEYWORDS[$language]) || isset(self::LINE_COMMENTS[$language]) => $this->highlightCode($code, $language),
            default => $this->escape($code),
        };
    }

    private function highlightCode(string $code, string $language): string
    {
        $patterns = [];
        if (\in_array($language, self::BLOCK_COMMENTS, true)) {
            $patterns[] = '(?<comment>/\*.*?\*/)';
        }
        foreach (self::LINE_COMMENTS[$language] ?? [] as $marker) {
            $patterns[] = '(?<comment'.\count($patterns).'>'.preg_quote($marker, '~').'[^\n]*)';
        }
        $patterns[] = '(?<string>"(?:\\\\.|[^"\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\n])*\'|`(?:\\\\.|[^`\\\\])*`)';
        $patterns[] = '(?<number>\b\d+(?:\.\d+)?\b)';
        if (isset(self::KEYWORDS[$language])) {
            $insensitive = 'sql' === $language ? '(?i)' : '';
            $patterns[] = '(?<keyword>'.$insensitive.'\b(?:'.str_replace(' ', '|', self::KEYWORDS[$language]).')\b)';
        }

        return $this->replaceTokens($code, '~'.implode('|', $patterns).'~su');
    }

    /**
     * HTML and Twig: comments, tags (with strings inside them) and, for Twig, the template tags.
     */
    private function highlightMarkup(string $code, string $commentStart, string $commentEnd): string
    {
        $pattern = '~(?<comment>'.preg_quote($commentStart, '~').'.*?'.preg_quote($commentEnd, '~').')'
            .'|(?<keyword>\{[{%].*?[%}]\})'
            .'|(?<tag></?[a-zA-Z][^>\n]*>)~su';

        return $this->replaceTokens($code, $pattern);
    }

    private function replaceTokens(string $code, string $pattern): string
    {
        $html = '';
        $offset = 0;

        if (false === preg_match_all($pattern, $code, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return $this->escape($code);
        }

        foreach ($matches as $match) {
            [$text, $position] = $match[0];
            $class = 'plain';
            foreach ($match as $name => $group) {
                if (\is_string($name) && -1 !== $group[1] && '' !== $group[0]) {
                    $class = (string) preg_replace('/\d+$/', '', $name);
                    break;
                }
            }

            $html .= $this->escape(substr($code, $offset, $position - $offset));
            $html .= '<span class="code-'.$class.'">'.$this->escape($text).'</span>';
            $offset = $position + \strlen($text);
        }

        return $html.$this->escape(substr($code, $offset));
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
