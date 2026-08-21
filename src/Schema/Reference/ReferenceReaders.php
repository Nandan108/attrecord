<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema\Reference;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Schema\ReferenceReader;
use Nandan108\Attrecord\SqlDialect;

/**
 * Picks the {@see ReferenceReader} matching a dialect.
 *
 * Separate from {@see SqlDialect} on purpose. Every method there builds a SQL string and executes
 * nothing, which is what makes a dialect testable without a database; reading a catalogue is the
 * opposite kind of operation, and on SQLite it is not one statement's worth of string at all.
 *
 * @api
 */
final class ReferenceReaders
{
    public static function for(SqlDialect $dialect): ReferenceReader
    {
        return match (true) {
            $dialect instanceof MysqlDialect  => new MysqlReferenceReader($dialect),
            $dialect instanceof PgsqlDialect  => new PgsqlReferenceReader($dialect),
            $dialect instanceof SqliteDialect => new SqliteReferenceReader($dialect),
            default                           => throw new SchemaException(
                'No ReferenceReader for dialect '.$dialect::class
                .' — inbound foreign keys are read from an engine-specific catalogue, so a custom '
                .'dialect needs its own reader.',
            ),
        };
    }

    public static function forConnection(Connection $connection): ReferenceReader
    {
        return self::for($connection->dialect);
    }
}
