<?php

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One site setting that is changed in the admin area. Read and write settings through App\Service\Settings.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'settings')]
class Setting
{
    #[ORM\Column(type: Types::JSON)]
    private mixed $value = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        private string $name,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }
}
