<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test Tier 1 Rate Limiting (120 req/minute per user)
     */
    public function test_tier1_rate_limiting_enforces_120_requests_per_minute()
    {
        $user = User::factory()->create();
        
        // Clear rate limiter for this user
        RateLimiter::clear('api:'.$user->id);

        $endpoint = '/api/v1/me';

        // Make 120 successful requests
        for ($i = 0; $i < 120; $i++) {
            $response = $this->actingAs($user)->getJson($endpoint);
            $response->assertStatus(200);
        }

        // The 121st request should be rate limited (429)
        $response = $this->actingAs($user)->getJson($endpoint);
        $response->assertStatus(429);
        $response->assertJsonStructure([
            'message',
            'retry_after_seconds',
            'retry_after',
            'rate_limit'
        ]);
        $response->assertHeader('Retry-After');
    }

    /**
     * Test Tier 2 Rate Limiting (45 req/minute per user for actions)
     */
    public function test_tier2_rate_limiting_enforces_45_requests_per_minute()
    {
        $user = User::factory()->create([
            'role' => 'super_admin'
        ]);
        
        // Clear rate limiter for this user
        RateLimiter::clear('actions:'.$user->id);

        $endpoint = '/api/v1/admin/users'; // A route that has throttle:actions

        // Make 45 requests (they might fail validation 422, but still count against rate limit)
        for ($i = 0; $i < 45; $i++) {
            $response = $this->actingAs($user)->postJson($endpoint, []);
            // 422 Unprocessable Entity is expected because we send empty data
            // 403 Forbidden is possible if company middleware blocks it for some reason
            $this->assertContains($response->status(), [201, 422, 403]);
        }

        // The 46th request should be rate limited (429)
        $response = $this->actingAs($user)->postJson($endpoint, []);
        $response->assertStatus(429);
        $response->assertJsonStructure([
            'message',
            'retry_after_seconds',
            'retry_after',
            'rate_limit'
        ]);
        $response->assertHeader('Retry-After');
    }

    /**
     * Test Tier 3 Rate Limiting (15 req/minute per user for heavy processing)
     */
    public function test_tier3_rate_limiting_enforces_15_requests_per_minute()
    {
        $user = User::factory()->create([
            'role' => 'super_admin'
        ]);
        
        // Clear rate limiter for this user
        RateLimiter::clear('heavy:'.$user->id);

        $endpoint = '/api/v1/admin/users/bulk-import'; // A route that has throttle:heavy

        // Make 15 requests
        for ($i = 0; $i < 15; $i++) {
            $response = $this->actingAs($user)->postJson($endpoint, []);
            // Expect validation or authorization errors, but not 429
            $this->assertContains($response->status(), [200, 422, 403]);
        }

        // The 16th request should be rate limited (429)
        $response = $this->actingAs($user)->postJson($endpoint, []);
        $response->assertStatus(429);
        $response->assertJsonStructure([
            'message',
            'retry_after_seconds',
            'retry_after',
            'rate_limit'
        ]);
        $response->assertHeader('Retry-After');
    }

    /**
     * Test Section 4 Standard Error Response (HTTP 429) and Clamped Headers
     */
    public function test_section4_rate_limiter_standard_response_and_clamped_headers()
    {
        $user = User::factory()->create();
        RateLimiter::clear('api:'.$user->id);

        // Exhaust Tier 1 quota
        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($user)->getJson('/api/v1/me');
        }

        // Trigger 429
        $response = $this->actingAs($user)->getJson('/api/v1/me');
        $response->assertStatus(429);

        // Verify JSON response according to Section 4 format
        $data = $response->json();
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('retry_after_seconds', $data);
        $this->assertArrayHasKey('retry_after', $data);
        $this->assertArrayHasKey('rate_limit', $data);
        $this->assertTrue($data['rate_limit']);
        $this->assertIsInt($data['retry_after_seconds']);
        $this->assertLessThanOrEqual(60, $data['retry_after_seconds']);
        $this->assertGreaterThanOrEqual(1, $data['retry_after_seconds']);

        // Verify HTTP Headers according to Section 4
        $retryAfterHeader = (int) $response->headers->get('Retry-After');
        $this->assertLessThanOrEqual(60, $retryAfterHeader);
        $this->assertGreaterThanOrEqual(1, $retryAfterHeader);
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertEquals(0, (int) $response->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * Test Tier 4 Rate Limiting: Login (5 attempts/min per email)
     */
    public function test_tier4_login_rate_limiting_enforces_5_attempts_per_minute_by_email()
    {
        $testEmail = 'ratelimit-test@expenseflow.local';
        RateLimiter::clear('login:'.$testEmail);

        // Make 5 failed login attempts
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/login', [
                'email' => $testEmail,
                'password' => 'wrongpassword123',
            ], [
                'X-Platform' => 'web',
            ]);
            // Should be 401 or 422, but not 429
            $this->assertNotEquals(429, $response->status());
        }

        // The 6th attempt should be blocked with 429
        $response = $this->postJson('/api/v1/login', [
            'email' => $testEmail,
            'password' => 'wrongpassword123',
        ], [
            'X-Platform' => 'web',
        ]);

        $response->assertStatus(429);
        $response->assertJsonStructure([
            'message',
            'retry_after_seconds',
            'retry_after',
            'rate_limit'
        ]);
        $response->assertHeader('Retry-After');
        $this->assertLessThanOrEqual(60, (int) $response->headers->get('Retry-After'));
    }

    /**
     * Test Tier 4 Rate Limiting: OTP Send (5 requests per 10 minutes)
     */
    public function test_tier4_otp_rate_limiting_enforces_5_requests_per_10_minutes()
    {
        $testEmail = 'otp-test@expenseflow.local';
        RateLimiter::clear('otp_send:'.$testEmail);

        // Make 5 requests
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/forgot-password/send-otp', [
                'email' => $testEmail,
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        // The 6th request should trigger 429 with Section 4 response
        $response = $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'email' => $testEmail,
        ]);

        $response->assertStatus(429);
        $response->assertJsonStructure([
            'message',
            'retry_after_seconds',
            'retry_after',
            'rate_limit'
        ]);
        $response->assertHeader('Retry-After');
        $this->assertLessThanOrEqual(60, (int) $response->headers->get('Retry-After'));
    }

    /**
     * Checklist #1: Uji Beban Normal (General Navigation)
     * Melakukan 50 request navigasi halaman dalam 30 detik -> Harus 200 OK tanpa blokir.
     */
    public function test_checklist_1_general_navigation_normal_load_under_limit()
    {
        $user = User::factory()->create();
        RateLimiter::clear('api:'.$user->id);

        for ($i = 0; $i < 50; $i++) {
            $response = $this->actingAs($user)->getJson('/api/v1/me');
            $response->assertStatus(200);
        }
    }

    /**
     * Checklist #2: Uji Batas Tindakan (Actions Throttling)
     * Melakukan spam clock-in/approval/mutasi 50 kali dalam burst -> Request ke-46 harus 429.
     */
    public function test_checklist_2_actions_throttling_triggers_at_46th_request()
    {
        $user = User::factory()->create([
            'role' => 'super_admin'
        ]);
        RateLimiter::clear('actions:'.$user->id);

        $endpoint = '/api/v1/admin/users';

        for ($i = 0; $i < 45; $i++) {
            $response = $this->actingAs($user)->postJson($endpoint, []);
            $this->assertNotEquals(429, $response->status());
        }

        // Request ke-46 harus 429 Too Many Requests
        $response = $this->actingAs($user)->postJson($endpoint, []);
        $response->assertStatus(429);
        $response->assertJsonStructure([
            'message',
            'retry_after_seconds',
            'retry_after',
            'rate_limit'
        ]);
    }

    /**
     * Checklist #3: Uji Batas Fitur Berat (Heavy Throttling)
     * Melakukan loop upload berkas/export 16 kali beruntun -> Request ke-16 ditolak 429.
     */
    public function test_checklist_3_heavy_throttling_triggers_at_16th_request()
    {
        $user = User::factory()->create([
            'role' => 'super_admin'
        ]);
        RateLimiter::clear('heavy:'.$user->id);

        $endpoint = '/api/v1/admin/users/bulk-import';

        for ($i = 0; $i < 15; $i++) {
            $response = $this->actingAs($user)->postJson($endpoint, []);
            $this->assertNotEquals(429, $response->status());
        }

        // Request ke-16 harus ditolak dengan status 429
        $response = $this->actingAs($user)->postJson($endpoint, []);
        $response->assertStatus(429);
        $response->assertJsonStructure([
            'message',
            'retry_after_seconds',
            'retry_after',
            'rate_limit'
        ]);
    }

    /**
     * Checklist #4: Uji Independensi Antar-User (Multi-Tenant IP Test)
     * User A dan User B login dari IP publik yang sama.
     * User A menghabiskan kuota request Tier 3 miliknya hingga 429.
     * User B melakukan request Tier 3 -> Harus tetap berhasil (tidak 429).
     */
    public function test_checklist_4_multi_tenant_ip_user_independence()
    {
        $sharedIp = '103.20.188.42'; // Simulasi 1 IP publik kantor cabang

        $userA = User::factory()->create(['role' => 'super_admin']);
        $userB = User::factory()->create(['role' => 'super_admin']);

        RateLimiter::clear('heavy:'.$userA->id);
        RateLimiter::clear('heavy:'.$userB->id);

        $endpoint = '/api/v1/admin/users/bulk-import';

        // User A menghabiskan kuota 15 request dari IP bersama
        for ($i = 0; $i < 15; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $sharedIp])
                ->actingAs($userA)
                ->postJson($endpoint, []);
            $this->assertNotEquals(429, $response->status());
        }

        // Request ke-16 oleh User A -> Ditolak 429
        $responseA = $this->withServerVariables(['REMOTE_ADDR' => $sharedIp])
            ->actingAs($userA)
            ->postJson($endpoint, []);
        $responseA->assertStatus(429);

        // User B yang berada di IP yang sama melakukan request Tier 3 -> Tetap aman, TIDAK 429
        $responseB = $this->withServerVariables(['REMOTE_ADDR' => $sharedIp])
            ->actingAs($userB)
            ->postJson($endpoint, []);
        $this->assertNotEquals(429, $responseB->status());
    }
}
