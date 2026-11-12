<?php

declare(strict_types=1);

namespace App\Shop\Entity;

use App\Shop\Enum\SearchOutcome;
use App\Shop\Repository\SearchStatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Daily count of the searches of the producer directory, per department and outcome: the map of
 * the producers to recruit (SPEC §11). An aggregate only: no IP, no cookie, no searched text.
 */
#[ORM\Entity(repositoryClass: SearchStatRepository::class, readOnly: true)]
#[ORM\UniqueConstraint(name: 'uniq_search_stat_day', columns: ['day', 'department', 'outcome'])]
final class SearchStat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $day,
        /** Department code ("26", "974", "20" for Corsica); empty when unknown (search by position). */
        #[ORM\Column(length: 3)]
        private string $department,
        #[ORM\Column(length: 20, enumType: SearchOutcome::class)]
        private SearchOutcome $outcome,
        #[ORM\Column]
        private int $searches = 1,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getDepartment(): string
    {
        return $this->department;
    }

    public function getOutcome(): SearchOutcome
    {
        return $this->outcome;
    }

    public function getSearches(): int
    {
        return $this->searches;
    }
}
