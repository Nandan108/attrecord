<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Tests\Support;

/**
 * Marks the three backend bases — {@see IntegrationTestCase}, {@see PgsqlIntegrationTestCase},
 * {@see SqliteIntegrationTestCase} — so a shared `*Cases` trait can require "one of them" with
 * `@phpstan-require-implements`.
 *
 * The bases share no parent below PHPUnit's `TestCase`, and `require-extends` names a single class,
 * so a union of the three cannot express that requirement; this interface can.
 */
interface BackendIntegrationTest
{
}
