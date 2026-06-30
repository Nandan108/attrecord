<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Unit;

use Nandan108\Attrecord\Exception\AttrecordException;
use Nandan108\Attrecord\Exception\LockAssertionException;
use Nandan108\Attrecord\Exception\LockTierConflictException;
use Nandan108\Attrecord\Exception\MissingLockTierException;
use Nandan108\Attrecord\Exception\RecordDeleteException;
use Nandan108\Attrecord\Exception\RecordNotFoundException;
use Nandan108\Attrecord\Exception\RecordSaveException;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Exception\SchemaException;
use Nandan108\Attrecord\Exception\TransactionException;
use Nandan108\Attrecord\Tests\Fixtures\PostRecord;
use Nandan108\Attrecord\Tests\Fixtures\UserRecord;
use PHPUnit\Framework\TestCase;

/**
 * Covers the exception taxonomy: message formatting, error class, and previous-exception
 * chaining. All attrecord exceptions extend {@see AttrecordException} (a RuntimeException).
 */
final class ExceptionsTest extends TestCase
{
    public function testAllExtendAttrecordException(): void
    {
        $exceptions = [
            new LockAssertionException(UserRecord::class, 7),
            new LockTierConflictException(UserRecord::class, PostRecord::class, 3),
            new MissingLockTierException(UserRecord::class),
            new RecordNotFoundException(UserRecord::class, 42),
            new RecordValidationException('bad'),
            new RecordDeleteException('nope'),
            new RecordSaveException('boom'),
            new TransactionException('tx'),
            new SchemaException('schema'),
            new AttrecordException('base'),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(AttrecordException::class, $e);
            $this->assertInstanceOf(\RuntimeException::class, $e);
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function testLockAssertionExceptionMessage(): void
    {
        $e = new LockAssertionException(UserRecord::class, 7);
        $this->assertStringContainsString('assertLocked()', $e->getMessage());
        $this->assertStringContainsString(UserRecord::class.'(7)', $e->getMessage());
    }

    public function testLockTierConflictExceptionMessage(): void
    {
        $e = new LockTierConflictException(UserRecord::class, PostRecord::class, 3);
        $this->assertStringContainsString(UserRecord::class, $e->getMessage());
        $this->assertStringContainsString(PostRecord::class, $e->getMessage());
        $this->assertStringContainsString('LockTier(3)', $e->getMessage());
    }

    public function testMissingLockTierExceptionMessage(): void
    {
        $e = new MissingLockTierException(UserRecord::class);
        $this->assertStringContainsString(UserRecord::class, $e->getMessage());
        $this->assertStringContainsString('#[LockTier(n)]', $e->getMessage());
    }

    public function testRecordNotFoundExceptionMessage(): void
    {
        $e = new RecordNotFoundException(UserRecord::class, 'abc');
        $this->assertStringContainsString(UserRecord::class, $e->getMessage());
        $this->assertStringContainsString('abc', $e->getMessage());
    }

    public function testWrappingExceptionsCarryPrevious(): void
    {
        $prev = new \RuntimeException('driver error');

        foreach ([
            new RecordDeleteException('delete failed', $prev),
            new RecordSaveException('save failed', $prev),
            new TransactionException('tx failed', $prev),
        ] as $e) {
            $this->assertSame($prev, $e->getPrevious());
            $this->assertSame(0, $e->getCode());
        }
    }
}
