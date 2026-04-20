<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Exception\AttrecordException;
use Nandan108\Attrecord\NamedPlaceholderSql;
use PHPUnit\Framework\TestCase;

final class NamedPlaceholderSqlTest extends TestCase
{
    public function testPositionalArrayPassedThrough(): void
    {
        $result = NamedPlaceholderSql::positional('a = ? AND b = ?', [1, 'foo']);

        $this->assertSame('a = ? AND b = ?', $result['sql']);
        $this->assertSame([1, 'foo'], $result['params']);
    }

    public function testEmptyPositionalPassedThrough(): void
    {
        $result = NamedPlaceholderSql::positional('SELECT 1', []);

        $this->assertSame('SELECT 1', $result['sql']);
        $this->assertSame([], $result['params']);
    }

    public function testNamedParamsConverted(): void
    {
        $result = NamedPlaceholderSql::positional(
            '`name` = :name AND `age` > :age',
            ['name' => 'Alice', 'age' => 30],
        );

        $this->assertSame('`name` = ? AND `age` > ?', $result['sql']);
        $this->assertSame(['Alice', 30], $result['params']);
    }

    public function testNamedParamsRepeatedPlaceholder(): void
    {
        // Same named param used twice — each occurrence becomes its own positional ?
        $result = NamedPlaceholderSql::positional(
            ':x OR :x',
            ['x' => 42],
        );

        $this->assertSame('? OR ?', $result['sql']);
        $this->assertSame([42, 42], $result['params']);
    }

    public function testNullValueAllowed(): void
    {
        $result = NamedPlaceholderSql::positional(':val IS NULL OR val = :val', ['val' => null]);

        $this->assertSame('? IS NULL OR val = ?', $result['sql']);
        $this->assertSame([null, null], $result['params']);
    }

    public function testMissingNamedParamThrows(): void
    {
        $this->expectException(AttrecordException::class);
        $this->expectExceptionMessage('Missing SQL parameter ":missing"');

        NamedPlaceholderSql::positional(':missing', ['other' => 'value']);
    }

    public function testNonScalarParamThrows(): void
    {
        $this->expectException(AttrecordException::class);
        $this->expectExceptionMessage('SQL parameter ":val" must be scalar or null');

        /** @psalm-suppress InvalidArgument */
        NamedPlaceholderSql::positional(':val', ['val' => ['array']]);
    }
}
