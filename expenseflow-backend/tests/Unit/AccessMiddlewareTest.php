<?php

namespace Tests\Unit;

use App\Http\Middleware\AttendanceAccessMiddleware;
use App\Http\Middleware\ReceiptAccessMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class AccessMiddlewareTest extends TestCase
{
    private function requestAs(?User $user): Request
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function pass(): \Closure
    {
        return fn ($req) => response()->json(['ok' => true], 200);
    }

    public function test_receipt_middleware_izinkan_employee(): void
    {
        $user = new User(['role' => 'employee']);

        $response = (new ReceiptAccessMiddleware())
            ->handle($this->requestAs($user), $this->pass());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_receipt_middleware_blokir_non_employee(): void
    {
        $user = new User(['role' => 'finance']);

        $response = (new ReceiptAccessMiddleware())
            ->handle($this->requestAs($user), $this->pass());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('hanya untuk karyawan', $response->getContent());
    }

    public function test_attendance_middleware_izinkan_jika_enabled(): void
    {
        $user = new User(['role' => 'finance', 'attendance_enabled' => true]);

        $response = (new AttendanceAccessMiddleware())
            ->handle($this->requestAs($user), $this->pass());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_attendance_middleware_blokir_jika_disabled(): void
    {
        $user = new User(['role' => 'employee', 'attendance_enabled' => false]);

        $response = (new AttendanceAccessMiddleware())
            ->handle($this->requestAs($user), $this->pass());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('belum diaktifkan', $response->getContent());
    }
}
