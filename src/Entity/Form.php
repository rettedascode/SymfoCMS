<?php

namespace App\Entity;

use App\Repository\FormRepository;
use App\Service\FormDefinition;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A form built in the admin area and placed on pages with the form block.
 */
#[ORM\Entity(repositoryClass: FormRepository::class)]
#[ORM\Table(name: 'forms')]
class Form
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /**
     * The fields in display order; see App\Service\FormDefinition for their shape.
     *
     * @var list<array{id: string, type: string, label: string, required: bool, options: list<string>}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $fields = [];

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $submitLabel = '';

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    private string $successMessage = '';

    /** Where a notification is sent for every submission; nobody is notified when empty. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $notifyEmail = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    /**
     * @return list<array{id: string, type: string, label: string, required: bool, options: list<string>, join?: bool}>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * The fields as rows: fields that were placed side by side in the form builder share one.
     *
     * @return list<list<array<string, mixed>>>
     */
    public function getRows(): array
    {
        return FormDefinition::rows($this->fields);
    }

    /**
     * @param list<array{id: string, type: string, label: string, required: bool, options: list<string>, join?: bool}> $fields
     */
    public function setFields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    public function getSubmitLabel(): string
    {
        return $this->submitLabel;
    }

    public function setSubmitLabel(?string $submitLabel): static
    {
        $this->submitLabel = trim((string) $submitLabel);

        return $this;
    }

    public function getSuccessMessage(): string
    {
        return $this->successMessage;
    }

    public function setSuccessMessage(?string $successMessage): static
    {
        $this->successMessage = trim((string) $successMessage);

        return $this;
    }

    public function getNotifyEmail(): ?string
    {
        return $this->notifyEmail;
    }

    public function setNotifyEmail(?string $notifyEmail): static
    {
        $this->notifyEmail = '' === trim((string) $notifyEmail) ? null : trim((string) $notifyEmail);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
