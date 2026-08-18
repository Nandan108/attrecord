<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Schema;

/**
 * Compiled description of one table-level CHECK constraint, from a class-level {@see \Nandan108\Attrecord\Attribute\Check}.
 *
 * @api
 */
final class CheckDefinition
{
    public function __construct(
        /**
         * The name as it exists in the database — `chk_<prefix digest>_<declared name>_<expression
         * digest>`, built by {@see TableSchema::checkConstraintName()}.
         *
         * The two digests answer two different problems. The **prefix** digest keeps two installs
         * sharing one database apart, because MySQL scopes CHECK names per database rather than per
         * table; it is the same reasoning, and the same mechanism, as a foreign-key constraint name.
         *
         * The **expression** digest makes an edited expression a differently-named constraint. No
         * engine stores the expression you wrote — MySQL re-prints it with charset introducers and
         * its own brackets, PostgreSQL adds casts — so schema tooling comparing live against
         * declared cannot reliably tell "the author changed this rule" from "the engine spells it
         * differently", and the safe reading of an ambiguous comparison (leave it alone) is exactly
         * the one that lets a corrected rule never reach an existing database. Putting the digest in
         * the name sidesteps the comparison entirely: an edited expression *is* a new constraint,
         * name and all, so name-only convergence adds it and drops the old one with no expression
         * comparison anywhere. The cost is a name that carries six characters of noise.
         */
        public readonly string $constraintName,
        /** The name as written in the attribute — what error messages should quote back to the author. */
        public readonly string $declaredName,
        /** The boolean SQL expression, verbatim as declared. */
        public readonly string $expression,
    ) {
    }
}
