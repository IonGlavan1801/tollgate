<?php

namespace App\Application;

use App\Models\DemoSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DemoSessions
{
    public function create(): array
    {
        return DB::transaction(function () {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(731908)');
            }
            abort_if(DemoSession::whereNull('revoked_at')->where('expires_at', '>', now())->count() >= config('lab.max_active_sessions'), 503, 'The lab is at capacity. Please try again later.');
            abort_if(DemoSession::count() >= config('lab.max_stored_sessions'), 503, 'The lab needs a maintenance window.');
            $token = Str::random(64);
            $session = DemoSession::create(['id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(config('lab.session_minutes')), 'virtual_now' => now()]);

            return [$session, $token];
        }, 3);
    }

    public function requireActive(string $id, bool $lock = false): DemoSession
    {
        $query = DemoSession::whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $session = $query->firstOrFail();
        abort_if($session->revoked_at !== null || $session->expires_at->isPast(), 401, 'Demo session expired. Start a new session.');

        return $session;
    }

    public function revoke(string $id): void
    {
        DB::transaction(function () use ($id) {
            $this->requireActive($id, true)->update(['revoked_at' => now()]);
        });
    }
}
