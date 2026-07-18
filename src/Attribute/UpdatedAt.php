<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

/**
 * Marks a temporal column (`ColumnType::DateTime` / `Timestamp`, typed `\DateTimeImmutable`) as an
 * auto-managed **updated-at** timestamp: attrecord sets it to the current time on INSERT and on any
 * UPDATE that actually changes other columns (a clean no-op save does not bump it). Pairs with
 * {@see CreatedAt}.
 *
 * At most one `#[UpdatedAt]` column per Record.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UpdatedAt
{
}
