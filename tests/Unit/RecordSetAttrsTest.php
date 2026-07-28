<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * {@see Record::set()} — the array-assignment contract.
 *
 * A key that names no column used to be assigned anyway, creating a dynamic property. That is the
 * worst possible outcome for a typo: the write appears to succeed, the value is retrievable, and
 * no column ever reads it. It is also an upgrade blocker, dynamic property creation being
 * deprecated in PHP 8.2 and an `Error` in PHP 9.
 */
final class RecordSetAttrsTest extends TestCase
{
    protected function setUp(): void
    {
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    public function testSetAssignsDeclaredColumnProperties(): void
    {
        $user = (new UserRecord())->set(['name' => 'Alice', 'email' => 'a@example.test'], false);

        self::assertSame('Alice', $user->name);
        self::assertSame('a@example.test', $user->email);
    }

    public function testSetRejectsAKeyThatIsNotAColumnProperty(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('set(): "nmae" is not a column property');

        (new UserRecord())->set(['nmae' => 'typo'], false);
    }

    /** The message has to name the class too — the same typo is legal on a different Record. */
    public function testTheRejectionNamesTheRecordClassAndTheKnownProperties(): void
    {
        try {
            (new UserRecord())->set(['nmae' => 'typo'], false);
            self::fail('expected a SchemaException');
        } catch (SchemaException $e) {
            self::assertStringContainsString(UserRecord::class, $e->getMessage());
            self::assertStringContainsString('name', $e->getMessage(), 'the known properties are listed');
        }
    }

    /** newWith() delegates to set(), so it inherits the contract rather than bypassing it. */
    public function testNewWithRejectsUnknownKeysToo(): void
    {
        $this->expectException(SchemaException::class);

        UserRecord::newWith(['definitely_not_a_column' => 1]);
    }

    /**
     * The rejection must happen before assignment, or the dynamic property is created anyway and
     * the PHP 9 exposure survives the fix.
     */
    public function testNoDynamicPropertyIsCreatedWhenAKeyIsRejected(): void
    {
        $user = new UserRecord();

        try {
            $user->set(['totally_unknown_key' => 'x'], false);
        } catch (SchemaException) {
            // expected
        }

        self::assertFalse(property_exists($user, 'totally_unknown_key'));
    }

    public function testAnEmptyAttrsArrayIsANoOp(): void
    {
        $user = (new UserRecord())->set([], false);

        self::assertSame('', $user->name);
    }
}
