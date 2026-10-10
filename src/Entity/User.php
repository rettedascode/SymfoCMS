<?php

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity('email', message: 'user.email_taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 20, enumType: UserRole::class)]
    private UserRole $role = UserRole::Author;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Secret shared with the user's authenticator app; null while two-step login is off. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    /** Time step of the last accepted code, so that a code cannot be used twice. */
    #[ORM\Column(nullable: true)]
    private ?int $totpLastStep = null;

    /** @var list<string>|null fingerprints of the unused backup codes */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $backupCodes = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = mb_strtolower(trim((string) $email));

        return $this;
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

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [$this->role->value];
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isTwoFactorEnabled(): bool
    {
        return null !== $this->totpSecret;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): static
    {
        $this->totpSecret = $totpSecret;

        return $this;
    }

    public function getTotpLastStep(): ?int
    {
        return $this->totpLastStep;
    }

    public function setTotpLastStep(?int $totpLastStep): static
    {
        $this->totpLastStep = $totpLastStep;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getBackupCodes(): array
    {
        return $this->backupCodes ?? [];
    }

    /**
     * @param list<string> $backupCodes
     */
    public function setBackupCodes(array $backupCodes): static
    {
        $this->backupCodes = [] !== $backupCodes ? $backupCodes : null;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Logs a user out on their next request when their account was
     * deactivated or their role or password changed in the meantime.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->getId() === $this->id
            && $user->getEmail() === $this->email
            && $user->getPassword() === $this->password
            && $user->getRole() === $this->role
            && $user->isActive() === $this->active;
    }
}
