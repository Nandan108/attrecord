<?php

declare(strict_types=1);

namespace Nandan108\Attrecord;

use Nandan108\Attrecord\Exception\AttrecordException;

/**
 * Normalises named SQL placeholders (:name) into ordered positional placeholders (?).
 *
 * Positional arrays are passed through unchanged.
 *
 * @internal
 */
final class NamedPlaceholderSql
{
    /**
     * @param array<array-key, scalar|null> $params
     *
     * @return array{sql: string, params: list<scalar|null>}
     */
    public static function positional(string $sql, array $params): array
    {
        if (array_is_list($params)) {
            /** @var list<scalar|null> $params */
            return ['sql' => $sql, 'params' => $params];
        }

        /** @var list<scalar|null> $ordered */
        $ordered = [];

        $normalised = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $m) use ($params, &$ordered): string {
                $name = $m[1];
                if (!array_key_exists($name, $params)) {
                    throw new AttrecordException(
                        sprintf('Missing SQL parameter ":%s".', $name),
                    );
                }
                $value = $params[$name];
                if (!is_scalar($value) && null !== $value) {
                    throw new AttrecordException(
                        sprintf('SQL parameter ":%s" must be scalar or null.', $name),
                    );
                }
                $ordered[] = $value;

                return '?';
            },
            $sql,
        );

        if (null === $normalised) {
            throw new AttrecordException('SQL placeholder normalisation failed.');
        }

        return ['sql' => $normalised, 'params' => $ordered];
    }
}
