<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Marks a temporal column (`ColumnType::DateTime` / `Timestamp`, typed `\DateTimeImmutable`) as an
 * auto-managed **created-at** timestamp: attrecord sets it to the current time when the record is
 * first INSERTed, and never touches it again. Pairs with {@see UpdatedAt}.
 *
 * At most one `#[CreatedAt]` column per Record.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CreatedAt
{
}
