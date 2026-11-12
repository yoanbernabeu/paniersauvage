<?php

declare(strict_types=1);

namespace App\Ordering\Model;

use App\Ordering\Enum\OrderStatus;
use App\Ordering\Enum\SheetActionType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * JSON body of `pro_order_sheet_action`: one action of the delivery sheet, as queued by the browser.
 *
 * - `id`: identifier chosen by the browser (UUID), the same on every replay;
 * - `type`: `mark` (with `status` and the `expected` status the producer saw) or `weigh` (with
 *   `weights` and the `expectedWeights` the producer saw), weights in thousandths of a kilogram,
 *   `null` to clear one, keyed by order line id.
 */
final readonly class SheetActionPayload
{
    /**
     * @param array<array-key, mixed> $weights
     * @param array<array-key, mixed> $expectedWeights
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $id = '',
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [self::class, 'types'])]
        public string $type = '',
        #[Assert\Choice(choices: ['confirmed', 'delivered', 'not_delivered'])]
        public ?string $status = null,
        #[Assert\Choice(choices: ['confirmed', 'delivered', 'not_delivered', 'cancelled'])]
        public ?string $expected = null,
        public array $weights = [],
        public array $expectedWeights = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_map(static fn (SheetActionType $type): string => $type->value, SheetActionType::cases());
    }

    /**
     * @throws \InvalidArgumentException when the action is incomplete or a weight is not a whole number
     */
    public function toAction(): SheetAction
    {
        $type = SheetActionType::from($this->type);

        if (SheetActionType::Mark === $type) {
            if (null === $this->status || null === $this->expected) {
                throw new \InvalidArgumentException('A mark needs the status and the expected status.');
            }

            return new SheetAction(strtolower($this->id), $type, OrderStatus::from($this->status), OrderStatus::from($this->expected));
        }

        $weights = $this->weights($this->weights);
        if ([] === $weights) {
            throw new \InvalidArgumentException('A weighing needs at least one weight.');
        }

        return new SheetAction(strtolower($this->id), $type, weights: $weights, expectedWeights: $this->weights($this->expectedWeights));
    }

    /**
     * @param array<array-key, mixed> $weights
     *
     * @return array<int, int|null>
     */
    private function weights(array $weights): array
    {
        $parsed = [];
        foreach ($weights as $lineId => $weight) {
            // JSON keys made of digits are integers once decoded.
            if (!\is_int($lineId) || (null !== $weight && !\is_int($weight))) {
                throw new \InvalidArgumentException('Weights are whole thousandths, keyed by order line id.');
            }
            $parsed[$lineId] = $weight;
        }

        return $parsed;
    }
}
