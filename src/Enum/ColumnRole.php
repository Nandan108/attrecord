<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Enum;

/**
 * Who writes a column, and when — the one question that partitions a table's columns, answered from
 * metadata the schema already holds.
 *
 * Reassembling this at a call site means consulting four unrelated properties (`$pk`,
 * `ColumnDefinition::$isGenerated`, the three auto-managed column names, `$mutableColumns`) and
 * remembering all of them; the column most often forgotten is `#[Version]`, which *reads* like a
 * fact about the row until you ask who increments it.
 *
 * The motivating consumer is a **content-addressed** table, whose primary key is a digest of its own
 * facts: {@see self::Content} is exactly the set to hash, so the digest stops being a hand-written
 * list of column names that a later column can quietly fall out of.
 *
 * @see \Nandan108\Attrecord\Schema\TableSchema::columnRole()
 * @see \Nandan108\Attrecord\Schema\TableSchema::columnsByRole()
 *
 * @api
 */
enum ColumnRole: string
{
    /** The row's identity — supplied at insert, never updated. On a content-addressed table, the digest's own output. */
    case PrimaryKey = 'primary_key';

    /** `GENERATED ALWAYS`: computed by the engine, writable by nobody. */
    case Generated = 'generated';

    /** Written by attrecord, never stated by the caller — `#[CreatedAt]`, `#[UpdatedAt]`, `#[Version]`. */
    case Managed = 'managed';

    /** Yours, and still writable after insert: an {@see \Nandan108\Attrecord\Attribute\Mutable} column. */
    case Exempted = 'exempted';

    /**
     * Yours, stated at insert.
     *
     * On an {@see \Nandan108\Attrecord\Immutable} Record these are the columns the row's promise is
     * about, and so the ones a content digest should cover. On an ordinary Record they are simply
     * the columns you supply: the role says who provides the value, while whether it is then frozen
     * is a property of the class rather than of the column.
     */
    case Content = 'content';

    /** Who writes it, for a diagnostic that has to explain a refusal to a reader. */
    public function describe(): string
    {
        return match ($this) {
            self::PrimaryKey => "the row's identity, supplied at insert",
            self::Generated  => 'computed by the engine; writable by nobody',
            self::Managed    => 'written by attrecord (#[CreatedAt] / #[UpdatedAt] / #[Version])',
            self::Exempted   => 'yours, and still writable after insert (#[Mutable])',
            self::Content    => 'yours, stated at insert',
        };
    }
}
