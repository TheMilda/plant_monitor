<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\HerbsGardenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HerbsGardenRepository::class)]
#[ApiResource]
class HerbsGarden
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
