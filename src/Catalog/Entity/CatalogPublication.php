<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Repository\CatalogPublicationRepository;
use App\Shop\Entity\Shop;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opt-in publication of the shop's catalogue in the Data Food Consortium format at a stable public
 * URL (`shop_dfc_catalog`). Off by default: the producer downloads the file from /pro either way.
 */
#[ORM\Entity(repositoryClass: CatalogPublicationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_catalog_publication_shop', columns: ['shop_id'])]
final class CatalogPublication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $dfcPublic = false;

    /** When the public URL was last switched on or off. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $changedAt = null;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function isDfcPublic(): bool
    {
        return $this->dfcPublic;
    }

    public function setDfcPublic(bool $dfcPublic, \DateTimeImmutable $now): static
    {
        if ($dfcPublic !== $this->dfcPublic) {
            $this->dfcPublic = $dfcPublic;
            $this->changedAt = $now;
        }

        return $this;
    }

    public function getChangedAt(): ?\DateTimeImmutable
    {
        return $this->changedAt;
    }
}
