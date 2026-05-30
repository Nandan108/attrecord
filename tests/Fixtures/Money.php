<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Fixtures;

use Nandan108\Attrecord\JsonCastable;

/**
 * Minimal value object exercising the JsonCastable auto-cast path.
 */
final class Money implements JsonCastable
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }

    /**
     * @return array{amount: int, currency: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    #[\Override]
    public static function fromJson(array $data): static
    {
        return new self((int) ($data['amount'] ?? 0), (string) ($data['currency'] ?? ''));
    }
}
