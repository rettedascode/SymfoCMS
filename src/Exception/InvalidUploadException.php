<?php

namespace App\Exception;

/**
 * Thrown when an uploaded file is rejected. The message is a translation key of the "validators" domain.
 */
final class InvalidUploadException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(string $messageKey, private readonly array $parameters = [])
    {
        parent::__construct($messageKey);
    }

    /**
     * @return array<string, string|int>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
