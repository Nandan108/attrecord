<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Attribute;

use Nandan108\Attrecord\ColumnCaster;

/**
 * Base attribute for column casters: a #[Cast]-family attribute IS the ColumnCaster.
 *
 * Concrete casters extend this, declare their own #[Attribute(TARGET_PROPERTY)] marker
 * (for correct target validation), and add constructor parameters to configure
 * themselves, e.g.:
 *
 *   #[Column(ColumnType::Json, nullable: true)]
 *   #[JsonCaster(excludeNullFields: ['note'])]
 *   public ?array $payload = null;
 *
 * They MUST be stateless: one instance is created per property at schema-build time
 * (via ReflectionAttribute::newInstance()) and reused across every row of that Record
 * class. The framework never invokes a caster with a null value (see {@see ColumnCaster}).
 *
 * This abstract base intentionally carries NO `#[\Attribute]` marker: PHP does not honor
 * an inherited marker (a subclass without its own throws "non-attribute class" at
 * newInstance()), so every concrete caster declares its own
 * `#[\Attribute(\Attribute::TARGET_PROPERTY)]`. The base only provides the common type
 * used for lookup (`is_a($name, Cast::class, true)`) and the ColumnCaster contract.
 *
 * @api
 */
abstract class Cast implements ColumnCaster
{
}
