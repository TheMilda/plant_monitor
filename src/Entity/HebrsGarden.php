<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\HebrsGardenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HebrsGardenRepository::class)]
#[ApiResource]
class HebrsGarden
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
