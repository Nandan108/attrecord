<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Integration\Cases;

use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\Schema\InboundReference;
use Nandan108\Attrecord\Schema\Reference\ReferenceReaders;
use Nandan108\Attrecord\Schema\ReferenceReader;
use Nandan108\Attrecord\Tests\Fixtures\RefDocumentRecord;
use Nandan108\Attrecord\Tests\Fixtures\RefPartyRecord;

/**
 * Inbound foreign keys, against a real catalogue on each engine — the only place this can be tested,
 * since the whole feature is "what does the database say points at this row".
 *
 * @phpstan-require-extends \Nandan108\Attrecord\Tests\Support\IntegrationTestCase|\Nandan108\Attrecord\Tests\Support\PgsqlIntegrationTestCase|\Nandan108\Attrecord\Tests\Support\SqliteIntegrationTestCase
 */
trait ReferenceReaderCases
{
    /** @return list<class-string<Record>> */
    protected static function recordClasses(): array
    {
        // Parent first: the child's foreign keys point at it.
        return [RefPartyRecord::class, RefDocumentRecord::class];
    }

    private function reader(): ReferenceReader
    {
        return ReferenceReaders::forConnection(Record::connection());
    }

    private static function session(): \Nandan108\Attrecord\DbSession
    {
        return Record::connection()->session;
    }

    /** @param class-string<Record> $class */
    private static function table(string $class): string
    {
        return \Nandan108\Attrecord\Schema\TableSchema::fromClass($class)->tableName;
    }

    private function seedParties(string ...$hashes): void
    {
        $parties = [];
        foreach ($hashes as $hash) {
            $party = new RefPartyRecord();
            $party->content_hash = $hash;
            $party->name = 'party '.$hash;
            $parties[] = $party;
        }
        (new RecordSet($parties))->insertAll();
    }

    private function seedDocument(?string $buyer, ?string $shipTo): void
    {
        $doc = new RefDocumentRecord();
        $doc->buyer_party_id = $buyer;
        $doc->ship_to_party_id = $shipTo;
        $doc->save();
    }

    public function testBothInboundKeysAreFound(): void
    {
        $found = $this->reader()->inboundForeignKeys(self::session(), self::table(RefPartyRecord::class));

        $columns = array_map(static fn (InboundReference $r): string => $r->childColumn, $found);
        sort($columns);
        $this->assertSame(['buyer_party_id', 'ship_to_party_id'], $columns);

        foreach ($found as $ref) {
            $this->assertSame(self::table(RefDocumentRecord::class), $ref->childTable);
            $this->assertSame('content_hash', $ref->referencedColumn, 'the referenced column is not the parent PK by accident — it is the PK, but named');
            $this->assertNotSame('', $ref->constraintName);
        }
    }

    public function testTheDeleteRuleIsReportedPerConstraint(): void
    {
        $byColumn = [];
        foreach ($this->reader()->inboundForeignKeys(self::session(), self::table(RefPartyRecord::class)) as $ref) {
            $byColumn[$ref->childColumn] = $ref->onDelete;
        }

        // The two keys deliberately differ, so a reader that reported one constraint's rule for all
        // of them would be caught.
        $this->assertSame(ForeignKeyAction::Restrict, $byColumn['buyer_party_id']);
        $this->assertSame(ForeignKeyAction::SetNull, $byColumn['ship_to_party_id']);
    }

    public function testFilteringByReferencedColumnExcludesEverythingElse(): void
    {
        $reader = $this->reader();
        $table = self::table(RefPartyRecord::class);

        $this->assertCount(2, $reader->inboundForeignKeys(self::session(), $table, 'content_hash'));
        $this->assertSame([], $reader->inboundForeignKeys(self::session(), $table, 'name'));
    }

    public function testATableNothingPointsAtHasNoInboundKeys(): void
    {
        $this->assertSame([], $this->reader()->inboundForeignKeys(self::session(), self::table(RefDocumentRecord::class)));
    }

    public function testReferencedKeysReturnsOnlyTheReferencedSubset(): void
    {
        $this->seedParties('aaa', 'bbb', 'ccc');
        $this->seedDocument('aaa', null);

        $referenced = $this->referencedKeys(['aaa', 'bbb', 'ccc']);

        $this->assertSame(['aaa'], $referenced);
        // The complement is what a "safe to remove" screen actually wants.
        $this->assertSame(['bbb', 'ccc'], array_values(array_diff(['aaa', 'bbb', 'ccc'], $referenced)));
    }

    public function testAKeyReferencedTwiceIsReportedOnce(): void
    {
        // Same party as buyer and as consignee: two rows in the UNION, one key in the answer. A
        // caller counting the result would otherwise report two referrers where there is one party.
        $this->seedParties('dup');
        $this->seedDocument('dup', 'dup');

        $this->assertSame(['dup'], $this->referencedKeys(['dup']));
    }

    public function testSeveralKeysAreAnsweredTogether(): void
    {
        $this->seedParties('k1', 'k2', 'k3', 'k4');
        $this->seedDocument('k1', 'k3');
        $this->seedDocument(null, 'k4');

        $referenced = $this->referencedKeys(['k1', 'k2', 'k3', 'k4']);
        sort($referenced);

        $this->assertSame(['k1', 'k3', 'k4'], $referenced);
    }

    public function testAnEmptyKeySetAsksNothing(): void
    {
        $this->assertSame([], $this->referencedKeys([]));
    }

    public function testKeysOfATableNothingReferencesComeBackEmpty(): void
    {
        // No foreign key points at the document table, so the answer is "none referenced" without
        // any UNION to build — the early return, exercised rather than assumed.
        $this->seedParties('x1');
        $this->seedDocument('x1', null);

        $this->assertSame([], $this->reader()->referencedKeys(
            self::session(),
            self::table(RefDocumentRecord::class),
            'id',
            [1, 2, 3],
        ));
    }

    public function testAKeyThatDoesNotExistIsSimplyNotReferenced(): void
    {
        $this->seedParties('real');
        $this->seedDocument('real', null);

        $this->assertSame(['real'], $this->referencedKeys(['real', 'never-existed']));
    }

    /**
     * @param list<scalar> $keys
     *
     * @return list<scalar>
     */
    private function referencedKeys(array $keys): array
    {
        return $this->reader()->referencedKeys(
            self::session(),
            self::table(RefPartyRecord::class),
            'content_hash',
            $keys,
        );
    }
}
