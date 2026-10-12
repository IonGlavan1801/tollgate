<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Records
{
    public static function insert(string $table, array $values): void
    {
        DB::table($table)->insert($values + ['created_at' => now(), 'updated_at' => now()]);
    }

    public static function audit(string $session, string $correlation, string $action, string $status, array $details = []): void
    {
        self::insert('audit_events', ['demo_session_id' => $session, 'correlation_id' => $correlation, 'action' => $action, 'status' => $status, 'details' => json_encode($details, JSON_THROW_ON_ERROR)]);
    }

    public static function outbox(string $session, string $key, string $subject, string $body): void
    {
        DB::table('outbox_messages')->insertOrIgnore(['id' => (string) Str::uuid(), 'demo_session_id' => $session, 'dedup_key' => $key, 'type' => 'demo.email', 'payload' => json_encode(compact('subject', 'body'), JSON_THROW_ON_ERROR), 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
