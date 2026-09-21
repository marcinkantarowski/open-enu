<?php

declare(strict_types=1);

namespace OpenEnu\Kernel\Tests\Dto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use OpenEnu\Kernel\Dto\ConflictBody;
use OpenEnu\Kernel\Dto\ErrorResponse;
use OpenEnu\Kernel\Dto\ListResponse;
use OpenEnu\Kernel\Dto\PaginationRequest;
use OpenEnu\Kernel\Flags\StaticFlags;
use Symfony\Component\HttpFoundation\Request;

/**
 * The shapes every endpoint speaks, plus flag resolution.
 *
 * These are contracts with clients that do not live in this repository, so their
 * structure is asserted rather than assumed.
 */
#[CoversClass(PaginationRequest::class)]
#[CoversClass(ListResponse::class)]
#[CoversClass(ErrorResponse::class)]
#[CoversClass(ConflictBody::class)]
#[CoversClass(StaticFlags::class)]
final class ApiContractTest extends TestCase
{
    public function testPageSizeIsClamped(): void
    {
        // An unbounded ?size= is a trivial way to make a list endpoint read a
        // whole table into memory, and every endpoint would otherwise have to
        // remember to guard it.
        $huge = PaginationRequest::fromRequest(new Request(['size' => '100000']));
        self::assertSame(PaginationRequest::MAX_SIZE, $huge->size);

        $negative = PaginationRequest::fromRequest(new Request(['size' => '-5', 'page' => '0']));
        self::assertSame(1, $negative->size);
        self::assertSame(1, $negative->page);
    }

    public function testSortIsRestrictedToAWhitelist(): void
    {
        // An arbitrary client-supplied column reaching ORDER BY is an injection
        // vector; dropping it beats failing the whole request over presentation.
        $allowed = PaginationRequest::fromRequest(new Request(['sort' => 'name']), ['name', 'createdAt']);
        self::assertSame('name', $allowed->sort);

        $rejected = PaginationRequest::fromRequest(new Request(['sort' => 'password']), ['name']);
        self::assertNull($rejected->sort);
    }

    public function testOffsetFollowsFromPageAndSize(): void
    {
        self::assertSame(50, (new PaginationRequest(page: 3, size: 25))->offset());
    }

    public function testListResponsesCarryPaginationMetadata(): void
    {
        // Items live under `items` so metadata has somewhere to go without a
        // breaking change later - a bare array is a contract you cannot extend.
        $json = (new ListResponse(['a', 'b'], total: 7, page: 1, size: 2))->jsonSerialize();

        self::assertSame(['a', 'b'], $json['items']);
        self::assertSame(['total' => 7, 'page' => 1, 'size' => 2, 'pages' => 4], $json['meta']);
    }

    public function testErrorsAreAlwaysEnveloped(): void
    {
        $json = (new ErrorResponse('not_found', 'No such thing.', 404, requestId: 'REQ1'))->jsonSerialize();

        self::assertSame('not_found', $json['error']['code']);
        self::assertSame('REQ1', $json['error']['requestId']);
        // Absent rather than null: an empty details key invites clients to
        // branch on it.
        self::assertArrayNotHasKey('details', $json['error']);
    }

    public function testConflictBodiesCarryBothVersions(): void
    {
        $json = (new ConflictBody('Project', 'p-1', 2, 5))->jsonSerialize();

        self::assertSame(2, $json['yourVersion']);
        self::assertSame(5, $json['currentVersion']);
    }

    public function testFlagsCoerceConfiguredValues(): void
    {
        $flags = new StaticFlags([
            'a.on' => true,
            'a.string_on' => 'yes',
            'a.off' => false,
            'a.name' => 'hello',
            'a.limit' => 42,
        ]);

        self::assertTrue($flags->enabled('a.on'));
        self::assertTrue($flags->enabled('a.string_on'));
        self::assertFalse($flags->enabled('a.off'));
        self::assertSame('hello', $flags->string('a.name'));
        self::assertSame(42, $flags->int('a.limit'));
    }

    public function testAnUnknownFlagFallsBackToItsDefault(): void
    {
        // Defaulting to "off" for an unknown identifier means a typo silently
        // disables a feature, so the default is always explicit at the call site.
        $flags = new StaticFlags();

        self::assertFalse($flags->enabled('never.configured'));
        self::assertTrue($flags->enabled('never.configured', default: true));
    }
}
