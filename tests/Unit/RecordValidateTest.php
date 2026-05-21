<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `Record::validate()` hook + its interaction with `Record::set()`.
 *
 * Save-path validation (triggered by `Record::save()` and `RecordSet::saveAll()`) is
 * covered indirectly by the integration tests that exercise those paths.
 */
final class RecordValidateTest extends TestCase
{
    public function testBaseValidateIsNoop(): void
    {
        // Demonstrates Record's base implementation does nothing — a fixture without
        // a validate() override accepts any state, including state that would fail
        // ValidatingFixture::validate(). The assertion is "didn't throw" — captured
        // via expectNotToPerformAssertions, with a final pass-marker.
        $record = (new BaselineFixture())->set(['name' => '']);
        $record->validate();

        // Sanity check: same input rejected by the validating fixture
        $this->expectException(RecordValidationException::class);
        (new ValidatingFixture())->set(['name' => '']);
    }

    public function testSetValidatesByDefaultAndThrowsOnViolation(): void
    {
        $record = new ValidatingFixture();

        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('name must be non-empty');

        $record->set(['name' => '']);
    }

    public function testSetValidatesByDefaultAndPassesWhenValid(): void
    {
        $record = (new ValidatingFixture())->set(['name' => 'Alice']);

        $this->assertSame('Alice', $record->name);
    }

    public function testSetWithValidateFalseSkipsValidation(): void
    {
        // Caller can stage partial state across multiple set() calls without each
        // intermediate state having to be valid.
        $record = (new ValidatingFixture())->set(['name' => ''], validate: false);

        $this->assertSame('', $record->name);
    }

    public function testValidateThrownExceptionCarriesContext(): void
    {
        $record = new ValidatingFixture();

        try {
            $record->set(['name' => '']);
            $this->fail('Expected RecordValidationException to be thrown');
        } catch (RecordValidationException $e) {
            $this->assertSame(['field' => 'name'], $e->context);
        }
    }

    public function testChainingSetReturnsSelf(): void
    {
        $record = new ValidatingFixture();
        $result = $record->set(['name' => 'Alice']);

        $this->assertSame($record, $result);
    }
}

/**
 * Test fixture with a real domain invariant on `name`.
 *
 * No `#[Table]` is needed for in-memory `set()`/`validate()` tests — only `save()`
 * paths read schema.
 */
#[Table(name: 'attrecord_validating_fixtures')]
final class ValidatingFixture extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';

    #[\Override]
    public function validate(): void
    {
        if ('' === $this->name) {
            throw new RecordValidationException(
                'name must be non-empty',
                ['field' => 'name'],
            );
        }
    }
}

/**
 * Test fixture without a validate() override — demonstrates the base no-op behaviour.
 */
#[Table(name: 'attrecord_baseline_fixtures')]
final class BaselineFixture extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';
}
