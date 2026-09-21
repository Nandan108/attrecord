<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Dialect;

/**
 * Shared building blocks for the join-based bulk-upsert UPDATE (step 3 of the deadlock-safe upsert).
 *
 * The dialects render slightly different SQL — `UPDATE … JOIN` (MySQL) vs `UPDATE … FROM` (PG/SQLite),
 * and `IF` / `CASE WHEN` / `iif` for the per-row mask test — but they share the two hard, portable
 * pieces provided here: the per-row bitmask plan and the derived-table constructor.
 *
 * **Why a join, and why a runtime mask?** A set-based join applies one uniform SET expression to every
 * joined row, so per-row column selectivity cannot be baked in at build time (as the per-column CASE
 * form does by omitting WHENs). It must travel as data: a per-row integer mask with one bit per
 * *sparse* update column, tested at runtime — `IF(u._m0 & bit, u.col, t.col)`. A column changed by
 * *every* row is **uniform** and needs no bit (write `u.col` directly). One integer holds 63 usable
 * bits (bit 63 is the sign bit of a 64-bit signed integer), so > 63 sparse columns spill into
 * additional mask columns `_m0, _m1, …` — the common case (≤ 63) is a single `_m0`, or none at all
 * when every column is uniform.
 */
trait UpsertJoinBuilder
{
    /**
     * Classify each update column as uniform (changed by every row) or sparse (changed by some), assign
     * a global bit index to each sparse column, size the mask integers (63-bit groups), and compute each
     * row's mask value(s).
     *
     * With no dirty info ($rowDirtyColumns empty) every column is treated as uniform — the join then
     * writes every column's value to every row, matching the original "write everything" behaviour.
     *
     * @param list<string>              $updateColumns   non-PK columns to write
     * @param list<array<string, bool>> $rowDirtyColumns per row (aligned to the rows), the set of columns it changed
     * @param int                       $rowCount        number of rows
     *
     * @return array{sparseBits: array<string, int>, maskCount: int, perRowMasks: list<array<int, int>>}
     */
    private function computeUpsertMaskPlan(array $updateColumns, array $rowDirtyColumns, int $rowCount): array
    {
        $sparseBits = [];
        $bit = 0;
        foreach ($updateColumns as $col) {
            $dirty = 0;
            foreach ($rowDirtyColumns as $set) {
                if (isset($set[$col])) {
                    ++$dirty;
                }
            }
            // Uniform (no bit) when every row changed it — or when there is no dirty info at all.
            // Otherwise it is sparse and gets the next bit.
            if (0 !== $dirty && $dirty !== $rowCount) {
                $sparseBits[$col] = $bit++;
            }
        }

        $sparseCount = \count($sparseBits);
        $maskCount = 0 === $sparseCount ? 0 : \intdiv($sparseCount - 1, 63) + 1;

        $perRowMasks = [];
        for ($i = 0; $i < $rowCount; ++$i) {
            $masks = array_fill(0, $maskCount, 0);
            foreach ($sparseBits as $col => $b) {
                if (isset($rowDirtyColumns[$i][$col])) {
                    // Bits 0–62 only: 1 << 63 is negative (the sign bit) in 64-bit signed PHP/SQL.
                    $masks[\intdiv($b, 63)] |= 1 << ($b % 63);
                }
            }
            $perRowMasks[] = $masks;
        }

        return ['sparseBits' => $sparseBits, 'maskCount' => $maskCount, 'perRowMasks' => $perRowMasks];
    }

    /**
     * Render the inline derived table as `SELECT … UNION ALL SELECT …`. The first SELECT aliases the
     * columns (portable across MySQL/PG/SQLite); later SELECTs match by position. `UNION ALL` (no dedup)
     * and per-branch type resolution mean this constructor is identical on all three dialects and needs
     * no per-dialect `VALUES` typing.
     *
     * @param list<string>       $quotedColumns derived-table column names, already quoted
     * @param list<list<string>> $valueRows     per row, one SQL literal per column (aligned to $quotedColumns)
     */
    private function renderUpsertDerivedTable(array $quotedColumns, array $valueRows): string
    {
        $selects = [];
        foreach ($valueRows as $i => $values) {
            if (0 === $i) {
                $aliased = [];
                foreach ($values as $j => $literal) {
                    $aliased[] = $literal.' AS '.$quotedColumns[$j];
                }
                $selects[] = 'SELECT '.\implode(', ', $aliased);
            } else {
                $selects[] = 'SELECT '.\implode(', ', $values);
            }
        }

        return \implode(' UNION ALL ', $selects);
    }

    /**
     * Assemble the derived-table column names (quoted) and each row's aligned value list for the join:
     * every key member, then mask columns `_m0…`, then the update columns.
     *
     * @param list<string>          $columnNames   all columns in $rows order (unquoted)
     * @param list<list<string>>    $rows          SQL literals per row, in $columnNames order
     * @param list<string>          $updateColumns non-key columns to write
     * @param list<string>          $quotedPkCols  every key member, quoted, in key order
     * @param list<int>             $pkIndexes     each key member's index in $columnNames
     * @param int                   $maskCount     number of mask columns
     * @param list<array<int, int>> $perRowMasks   per row, its mask integer(s)
     *
     * @return array{columns: list<string>, valueRows: list<list<string>>}
     */
    private function buildUpsertDerivedColumns(array $quotedPkCols, array $columnNames, array $rows, array $updateColumns, array $pkIndexes, int $maskCount, array $perRowMasks): array
    {
        $columns = $quotedPkCols;
        for ($m = 0; $m < $maskCount; ++$m) {
            $columns[] = $this->quoteIdentifier('_m'.$m);
        }
        foreach ($updateColumns as $col) {
            $columns[] = $this->quoteIdentifier($col);
        }

        $updateIndexes = [];
        foreach ($updateColumns as $col) {
            $updateIndexes[] = (int) \array_search($col, $columnNames, true);
        }

        $valueRows = [];
        foreach ($rows as $i => $row) {
            $values = [];
            foreach ($pkIndexes as $ki) {
                $values[] = $row[$ki];
            }
            for ($m = 0; $m < $maskCount; ++$m) {
                $values[] = (string) $perRowMasks[$i][$m];
            }
            foreach ($updateIndexes as $ci) {
                $values[] = $row[$ci];
            }
            $valueRows[] = $values;
        }

        return ['columns' => $columns, 'valueRows' => $valueRows];
    }

    /**
     * The `IN` list naming the rows to lock: a flat list of key literals on a single-column key,
     * row-value tuples on a composite one.
     *
     * @param list<string>       $quotedPkCols every key member, quoted, in key order
     * @param list<list<string>> $rows         SQL literals per row, in $columnNames order
     * @param list<int>          $pkIndexes    each key member's index in $columnNames
     */
    private function renderKeyInList(array $quotedPkCols, array $rows, array $pkIndexes): string
    {
        if (1 === \count($quotedPkCols)) {
            return $quotedPkCols[0].' IN ('
                .\implode(', ', \array_map(static fn (array $row): string => $row[$pkIndexes[0]], $rows)).')';
        }

        $tuples = \array_map(
            static fn (array $row): string => '('.\implode(', ', \array_map(static fn (int $ki): string => $row[$ki], $pkIndexes)).')',
            $rows,
        );

        return '('.\implode(', ', $quotedPkCols).') IN ('.\implode(', ', $tuples).')';
    }

    /**
     * Ascending key order for the locking read — lexicographic over the whole key, since that
     * ordering is what makes concurrent upserts of overlapping batches deadlock-free.
     *
     * @param list<string> $quotedPkCols
     */
    private function renderKeyOrderBy(array $quotedPkCols): string
    {
        return \implode(', ', \array_map(static fn (string $c): string => $c.' ASC', $quotedPkCols));
    }

    /**
     * `t.a = u.a AND t.b = u.b` — the derived table is joined on the whole key, so a row is
     * matched by its identity rather than by one member of it.
     *
     * @param list<string> $quotedPkCols
     */
    private function renderKeyJoin(string $leftPrefix, string $rightPrefix, array $quotedPkCols): string
    {
        return \implode(' AND ', \array_map(
            static fn (string $c): string => "{$leftPrefix}.{$c} = {$rightPrefix}.{$c}",
            $quotedPkCols,
        ));
    }

    abstract public function quoteIdentifier(string $name): string;
}
