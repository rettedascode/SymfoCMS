<?php

namespace App\Transfer;

/**
 * What an import did: how much was imported and what could not be taken over.
 */
final class ImportReport
{
    /** @var array<string, int> what was imported => how many, e.g. ['pages' => 3] */
    private array $counts = [];

    /** @var list<array{subject: string, message: string, parameters: array<string, string>}> */
    private array $issues = [];

    public function count(string $what, int $by = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $by;
    }

    /**
     * @param string                $subject    what the note is about, e.g. the title of a post
     * @param string                $message    translation key
     * @param array<string, string> $parameters
     */
    public function issue(string $subject, string $message, array $parameters = []): void
    {
        $this->issues[] = ['subject' => $subject, 'message' => $message, 'parameters' => $parameters];
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    public function get(string $what): int
    {
        return $this->counts[$what] ?? 0;
    }

    /**
     * @return list<array{subject: string, message: string, parameters: array<string, string>}>
     */
    public function issues(): array
    {
        return $this->issues;
    }
}
