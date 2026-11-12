<?php

declare(strict_types=1);

namespace App\Delivery\Entity;

use App\Delivery\Enum\DeliveryPointType;
use App\Delivery\Repository\DeliveryPointRepository;
use App\Shop\Entity\Shop;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeliveryPointRepository::class)]
#[ORM\Index(name: 'idx_delivery_point_shop', columns: ['shop_id', 'archived'])]
#[ORM\Index(name: 'idx_delivery_point_location', columns: ['latitude', 'longitude'])]
#[ORM\Index(name: 'idx_delivery_point_commune', columns: ['commune_slug'])]
final class DeliveryPoint implements \Stringable
{
    /** Largest home delivery zone, in kilometres around its town. */
    public const int MAX_ZONE_RADIUS_KM = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    /** WGS 84 coordinates of the address, from the Base Adresse Nationale; null when unknown. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $geocodedAt = null;

    /** Town and postcode of the point, read from its address then confirmed by the geocoder. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $postcode = null;

    /** "valence-26": the directory page of the town (SPEC §11), derived from the town and its department. */
    #[ORM\Column(length: 140, nullable: true)]
    private ?string $communeSlug = null;

    /**
     * INSEE code of the town ("26362" for Valence), as the geocoder gives it (`citycode` of the BAN);
     * null until geocoded. A territorial instance restricts its directory with it (docs/INSTANCE.md).
     */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $inseeCode = null;

    /** Home delivery only: the town at the centre of the delivery zone, geocoded like an address. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $zoneTown = null;

    /** Home delivery only: radius of the delivery zone around $zoneTown, in kilometres. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(notInRangeMessage: 'Indique un rayon entre {{ min }} et {{ max }} km.', min: 1, max: self::MAX_ZONE_RADIUS_KM)]
    #[Assert\Expression('!this.isHome() or (value === null) === (this.getZoneTown() === null)', message: 'Indique la commune et le rayon de ta zone de livraison, ou aucun des deux.')]
    private ?int $zoneRadiusKm = null;

    /**
     * Not mapped: the address or the type changed since the point was loaded, so its coordinates
     * must be looked up again when it is saved (DeliveryPointGeocodingListener).
     */
    private bool $geocodingPending = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop,
        #[ORM\Column(length: 120)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        private string $name = '',
        #[ORM\Column(length: 20, enumType: DeliveryPointType::class)]
        private DeliveryPointType $type = DeliveryPointType::MeetingPoint,
    ) {
    }

    /**
     * Home delivery: the customer types their own address when ordering.
     */
    public function isHome(): bool
    {
        return DeliveryPointType::Home === $this->type;
    }

    /**
     * A home delivery that declared its zone (town + radius): visitors inside it find the shop.
     */
    public function hasDeliveryZone(): bool
    {
        return $this->isHome() && null !== $this->zoneTown && null !== $this->zoneRadiusKm;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function getType(): DeliveryPointType
    {
        return $this->type;
    }

    public function setType(DeliveryPointType $type): static
    {
        if ($type !== $this->type) {
            $this->type = $type;
            $this->invalidateCoordinates();
        }

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        if ($address !== $this->address) {
            $this->address = $address;
            $this->invalidateCoordinates();
            [$postcode, $city] = self::townOf($address);
            $this->setTown($city, $postcode);
        }

        return $this;
    }

    public function getZoneTown(): ?string
    {
        return $this->zoneTown;
    }

    public function setZoneTown(?string $zoneTown): static
    {
        $zoneTown = null === $zoneTown || '' === trim($zoneTown) ? null : trim($zoneTown);
        if ($zoneTown !== $this->zoneTown) {
            $this->zoneTown = $zoneTown;
            $this->invalidateCoordinates();
        }

        return $this;
    }

    public function getZoneRadiusKm(): ?int
    {
        return $this->zoneRadiusKm;
    }

    public function setZoneRadiusKm(?int $zoneRadiusKm): static
    {
        $this->zoneRadiusKm = $zoneRadiusKm;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    public function getCommuneSlug(): ?string
    {
        return $this->communeSlug;
    }

    public function getInseeCode(): ?string
    {
        return $this->inseeCode;
    }

    /**
     * Sets the town of the point (from its address, or as the geocoder names it), its INSEE code
     * (known from the geocoder only) and its directory slug.
     */
    public function setTown(?string $city, ?string $postcode, ?string $inseeCode = null): static
    {
        $this->city = null === $city || '' === trim($city) ? null : trim($city);
        $this->postcode = null === $postcode || '' === trim($postcode) ? null : trim($postcode);
        $inseeCode = null === $inseeCode ? '' : mb_strtoupper(trim($inseeCode));
        $this->inseeCode = 1 === preg_match('/^(\d{5}|2[AB]\d{3})$/', $inseeCode) ? $inseeCode : null;
        $this->communeSlug = null === $this->city || null === $this->postcode ? null : self::communeSlugOf($this->city, $this->postcode);

        return $this;
    }

    /**
     * "valence-26" for Valence (26000), "saint-denis-974" for La Réunion: unique across France for
     * a town name and its department.
     */
    public static function communeSlugOf(string $city, string $postcode): string
    {
        return mb_strtolower(new AsciiSlugger('fr')->slug($city)->toString()).'-'.self::departmentOf($postcode);
    }

    /**
     * Department code of a postcode: two digits, three overseas (97x, 98x), "20" for Corsica.
     */
    public static function departmentOf(string $postcode): string
    {
        return str_starts_with($postcode, '97') || str_starts_with($postcode, '98') ? substr($postcode, 0, 3) : substr($postcode, 0, 2);
    }

    /**
     * Postcode and town of the last line of an address ("…\n26400 Saoû" gives ["26400", "Saoû"]).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function townOf(?string $address): array
    {
        $address = trim((string) preg_replace('/\s+/u', ' ', (string) $address));

        if (1 === preg_match('/\b(?<postcode>\d{5})\s+(?<town>[^,\d][^,]*)$/u', $address, $matches)) {
            return [$matches['postcode'], trim($matches['town'])];
        }

        return [null, null];
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function setInstructions(?string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): static
    {
        $this->archived = $archived;

        return $this;
    }

    /**
     * A fixed place is found from its address, a home delivery from the town of its zone.
     */
    public function isGeocodable(): bool
    {
        return null !== $this->geocodingQuery();
    }

    /**
     * What the geocoder looks up, on one line: the address of a fixed point, the town of a home
     * delivery zone; null when there is none.
     */
    public function geocodingQuery(): ?string
    {
        $text = $this->isHome() ? $this->zoneTown : $this->address;
        $query = trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', (string) $text));

        return '' === $query ? null : $query;
    }

    public function isGeocodingPending(): bool
    {
        return $this->geocodingPending;
    }

    public function hasCoordinates(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }

    /**
     * Sets (or clears) the coordinates of the address. Explicit coordinates win over a pending lookup.
     */
    public function setCoordinates(?float $latitude, ?float $longitude, ?\DateTimeImmutable $geocodedAt = null): static
    {
        $this->latitude = null === $longitude ? null : $latitude;
        $this->longitude = null === $latitude ? null : $longitude;
        $this->geocodedAt = $this->hasCoordinates() ? $geocodedAt : null;
        $this->geocodingPending = false;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function getGeocodedAt(): ?\DateTimeImmutable
    {
        return $this->geocodedAt;
    }

    private function invalidateCoordinates(): void
    {
        $this->latitude = $this->longitude = $this->geocodedAt = null;
        $this->geocodingPending = true;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
