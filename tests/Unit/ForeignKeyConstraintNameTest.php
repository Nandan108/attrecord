<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\DdlOrderRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FK constraint names must be unique **per install**, because InnoDB scopes them per *database*
 * rather than per table.
 *
 * Two installs can legitimately share one database — a PrestaShop→WooCommerce cutover running both
 * hosts against it during the transition, or two WordPress sites at `wp_` and `blog_` on shared
 * hosting. Before this was fixed the table prefix was stripped out of the constraint name, so the
 * second `CREATE TABLE` died with errno 121, "duplicate key on write or update".
 */
final class ForeignKeyConstraintNameTest extends TestCase
{
    protected function tearDown(): void
    {
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    /** @return list<string> */
    private function fkNamesForPrefix(string $prefix): array
    {
        Record::setTablePrefix($prefix);
        TableSchema::clearCache();

        return array_map(
            static fn ($fk): string => $fk->constraintName,
            TableSchema::fromClass(DdlOrderRecord::class)->foreignKeys,
        );
    }

    #[Test]
    public function distinctPrefixesYieldDistinctConstraintNames(): void
    {
        // The reported case: prefixes differing only in the first underscore-delimited segment.
        $wp = $this->fkNamesForPrefix('wp_');
        $ps = $this->fkNamesForPrefix('ps_');
        $blog = $this->fkNamesForPrefix('blog_');

        self::assertNotEmpty($wp);
        self::assertNotSame($wp, $ps);
        self::assertNotSame($wp, $blog);
        self::assertNotSame($ps, $blog);
    }

    #[Test]
    public function multisiteStylePrefixStaysDistinct(): void
    {
        // `wp_2_` escaped the old bug by luck — the stripped segment left `2_` behind — so it must
        // stay covered, or a regression here would be invisible to multisite testing.
        self::assertNotSame($this->fkNamesForPrefix('wp_'), $this->fkNamesForPrefix('wp_2_'));
    }

    #[Test]
    public function theSamePrefixIsStableAcrossRebuilds(): void
    {
        // Deterministic: convergence tooling compares live names against declared ones, so the
        // name must not drift between two builds of the same schema.
        self::assertSame($this->fkNamesForPrefix('wp_'), $this->fkNamesForPrefix('wp_'));
    }

    #[Test]
    public function unprefixedInstallKeepsAReadableName(): void
    {
        // No prefix → no discriminator needed, and the full table name is kept. Note it is *not*
        // stripped to `ddl_orders`: that stripping was the defect, guessing a prefix by regex.
        self::assertSame(['fk_attrecord_ddl_orders_customer_id'], $this->fkNamesForPrefix(''));
    }

    #[Test]
    public function namesStayWithinTheIdentifierLimitForLongPrefixes(): void
    {
        // The discriminator is fixed-width, so the total cannot grow with the prefix — the property
        // that a raw (unstripped) prefix would have lacked.
        foreach (['wp_', 'wordpress_', 'a_very_long_hardened_random_prefix_9f3c2a_'] as $prefix) {
            foreach ($this->fkNamesForPrefix($prefix) as $name) {
                self::assertLessThanOrEqual(64, \strlen($name), "too long under prefix '{$prefix}': {$name}");
            }
        }
    }
}
