<?php

namespace App\Entity;

use App\Repository\FormSubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a visitor sent through a form.
 *
 * The answers are stored together with the labels of their fields, so a
 * submission stays readable after the form was changed. Nothing about the
 * visitor (IP address, browser) is stored.
 */
#[ORM\Entity(repositoryClass: FormSubmissionRepository::class)]
#[ORM\Table(name: 'form_submissions')]
#[ORM\Index(name: 'idx_form_submissions_inbox', columns: ['form_id', 'created_at'])]
class FormSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'is_read')]
    private bool $read = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array{label: string, value: string}> $answers
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Form $form,
        #[ORM\Column(type: Types::JSON)]
        private array $answers,
        /** Address of the page the form was sent from. */
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $pageUrl = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getForm(): Form
    {
        return $this->form;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function getPageUrl(): ?string
    {
        return $this->pageUrl;
    }

    public function isRead(): bool
    {
        return $this->read;
    }

    public function setRead(bool $read): static
    {
        $this->read = $read;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * A short text for lists: the first answers, joined.
     */
    public function getSummary(int $length = 90): string
    {
        $text = implode(' · ', array_filter(array_column($this->answers, 'value'), static fn (string $value): bool => '' !== $value));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
