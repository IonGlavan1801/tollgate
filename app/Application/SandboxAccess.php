<?php

namespace App\Application;

use App\Models\DemoSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SandboxAccess
{
    public function create(string $code): DemoSession
    {
        $expected = (string) config('lab.sandbox.access_code');
        abort_unless(config('lab.sandbox_enabled') && strlen($expected) >= 16, 503, 'Sandbox access is not configured.');
        abort_unless(hash_equals(hash('sha256', $expected), hash('sha256', $code)), 403, 'Invalid sandbox access code.');

        return DB::transaction(function (): DemoSession {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(731909)');
            }
            abort_if(DemoSession::where('environment', 'sandbox')->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= config('lab.sandbox.max_sessions'), 429, 'Sandbox session budget reached.');
            abort_if(DemoSession::count() >= config('lab.max_stored_sessions'), 503, 'The lab needs a maintenance window.');

            return DemoSession::create(['id' => (string) Str::uuid(), 'token_hash' => hash('sha256', Str::random(64)), 'environment' => 'sandbox', 'expires_at' => now()->addDays(max(1, min(30, (int) config('lab.sandbox.session_days')))), 'virtual_now' => now()]);
        }, 3);
    }

    public function requireActive(string $sessionId): DemoSession
    {
        abort_unless(config('lab.sandbox_enabled'), 503, 'Sandbox is disabled.');
        $session = app(DemoSessions::class)->requireActive($sessionId);
        abort_unless($session->environment === 'sandbox', 403, 'Launch a sandbox session first.');

        return $session;
    }
}
