<?php

namespace App\Service;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Site settings that are edited in the admin area and stored in the database.
 * All settings are read with a single query per request.
 */
final class Settings implements ResetInterface
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->all()[$name] ?? $default;
    }

    public function set(string $name, mixed $value): void
    {
        $setting = $this->repository->find($name) ?? new Setting($name);
        $setting->setValue($value);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        $this->values = null;
    }

    public function reset(): void
    {
        $this->values = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        if (null === $this->values) {
            $this->values = [];

            try {
                foreach ($this->repository->findAll() as $setting) {
                    $this->values[$setting->getName()] = $setting->getValue();
                }
            } catch (DatabaseException) {
                // The settings table does not exist yet (before the migrations ran): use the defaults.
            }
        }

        return $this->values;
    }
}
