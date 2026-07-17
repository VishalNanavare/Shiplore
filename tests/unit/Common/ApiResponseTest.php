<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Libraries/ApiResponse.php';

use App\Libraries\ApiResponse;

/** Phase 6 — standard API envelope (docs/architecture/07-API-ARCHITECTURE.md §2–3). */
final class ApiResponseTest extends TestCase
{
    public function testSuccessEnvelope(): void
    {
        $r = ApiResponse::success(['id' => 1], ['request_id' => 'req1']);
        $this->assertTrue($r['success']);
        $this->assertSame(['id' => 1], $r['data']);
        $this->assertSame('req1', $r['meta']['request_id']);
    }

    public function testCollectionEnvelopeCarriesPagination(): void
    {
        $r = ApiResponse::collection([['id' => 1]], ['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1]);
        $this->assertTrue($r['success']);
        $this->assertSame([['id' => 1]], $r['data']);
        $this->assertSame(1, $r['meta']['pagination']['total']);
    }

    public function testErrorEnvelopeHasNoDataKey(): void
    {
        $r = ApiResponse::error('VALIDATION_ERROR', 'Bad input', [['field' => 'phone', 'issue' => 'invalid_format']]);
        $this->assertFalse($r['success']);
        $this->assertArrayNotHasKey('data', $r);
        $this->assertSame('VALIDATION_ERROR', $r['error']['code']);
        $this->assertSame('Bad input', $r['error']['message']);
        $this->assertCount(1, $r['error']['details']);
    }

    public function testStatusForMapping(): void
    {
        $this->assertSame(422, ApiResponse::statusFor('VALIDATION_ERROR'));
        $this->assertSame(403, ApiResponse::statusFor('FORBIDDEN'));
        $this->assertSame(404, ApiResponse::statusFor('NOT_FOUND'));
        $this->assertSame(401, ApiResponse::statusFor('UNAUTHENTICATED'));
        $this->assertSame(409, ApiResponse::statusFor('CONFLICT'));
        $this->assertSame(429, ApiResponse::statusFor('RATE_LIMITED'));
        $this->assertSame(500, ApiResponse::statusFor('SOMETHING_UNKNOWN'));
    }
}
