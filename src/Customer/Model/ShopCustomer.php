<?php

declare(strict_types=1);

namespace App\Customer\Model;

use App\Customer\Entity\Customer;

/**
 * One line of "Mes clients": a customer of the shop (ordered there, follows it, or was entered
 * without e-mail by the producer) and what the shop knows of them. Every figure counts the orders
 * of this shop only.
 *
 * @phpstan-type ShopCustomerRow array{id: int, email: ?string, without_email: bool, first_name: ?string, last_name: ?string, phone: ?string, follower: bool, email_consent: bool, followed_at: ?string, order_count: int, last_order_date: ?string, delivered_cents: int, absences: int}
 */
final readonly class ShopCustomer
{
    public function __construct(
        public int $id,
        /** Null without e-mail; an erased account's placeholder is never shown. */
        public ?string $email,
        public bool $withoutEmail,
        public bool $erased,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $phone,
        public bool $follower,
        /** Accepts the e-mails announcing the shop's sales (only a follower may). */
        public bool $emailConsent,
        public ?\DateTimeImmutable $followedAt,
        /** Orders not cancelled. */
        public int $orderCount,
        /** Delivery date of the latest order not cancelled. */
        public ?\DateTimeImmutable $lastOrderDate,
        /** Sum of the orders picked up, at the weighed amount. */
        public int $deliveredCents,
        /** Orders not picked up (`not_delivered`). */
        public int $absences,
    ) {
    }

    /**
     * "Prénom Nom", else the e-mail address, else the phone number.
     */
    public function displayName(): string
    {
        $name = trim(($this->firstName ?? '').' '.($this->lastName ?? ''));

        return match (true) {
            $this->erased => 'Client effacé',
            '' !== $name => $name,
            null !== $this->email => $this->email,
            default => $this->phone ?? 'Client sans nom',
        };
    }

    /**
     * @param ShopCustomerRow $row a row of ShopCustomers (PostgreSQL integers and booleans come back typed)
     */
    public static function fromRow(array $row): self
    {
        $email = self::text($row['email']);
        $erased = null !== $email && Customer::isAnonymizedEmail($email);

        return new self(
            id: $row['id'],
            email: $erased ? null : $email,
            withoutEmail: $row['without_email'],
            erased: $erased,
            firstName: $erased ? null : self::text($row['first_name']),
            lastName: $erased ? null : self::text($row['last_name']),
            phone: $erased ? null : self::text($row['phone']),
            follower: $row['follower'],
            emailConsent: $row['email_consent'],
            followedAt: null === $row['followed_at'] ? null : new \DateTimeImmutable($row['followed_at']),
            orderCount: $row['order_count'],
            lastOrderDate: null === $row['last_order_date'] ? null : new \DateTimeImmutable($row['last_order_date']),
            deliveredCents: $row['delivered_cents'],
            absences: $row['absences'],
        );
    }

    private static function text(?string $value): ?string
    {
        return null !== $value && '' !== trim($value) ? $value : null;
    }
}
