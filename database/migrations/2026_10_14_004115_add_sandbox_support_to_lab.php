<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->string('environment')->default('simulated')->index();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable()->index();
            $table->timestamp('reconciliation_attempted_at')->nullable()->index();
            $table->unsignedInteger('snapshot_version')->default(0);
            $table->timestamp('cleanup_completed_at')->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
            $table->index('order_id');
        });
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique();
        });
        Schema::table('subscription_changes', function (Blueprint $table) {
            $table->json('provider_parameters')->nullable();
        });
        Schema::create('sandbox_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->foreignId('price_id')->constrained();
            $table->string('external_id');
            $table->string('credential_hash', 64);
            $table->unique(['provider', 'price_id', 'credential_hash']);
            $table->timestamps();
        });
        Schema::create('sandbox_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained();
            $table->string('action');
            $table->string('operation_key', 64)->unique();
            $table->string('credential_hash', 64);
            $table->json('parameters');
            $table->json('result')->nullable();
            $table->string('status')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('retry_until');
            $table->string('failure_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->select('order_id')->groupBy('order_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Sandbox renewals prevent rollback. Keep the migration and roll back application images only.');
        }
        Schema::dropIfExists('sandbox_operations');
        Schema::dropIfExists('sandbox_catalog');
        Schema::table('subscription_changes', fn (Blueprint $table) => $table->dropColumn('provider_parameters'));
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['reconciled_at']);
            $table->dropIndex(['reconciliation_attempted_at']);
            $table->dropColumn(['reconciled_at', 'reconciliation_attempted_at', 'cleanup_completed_at', 'snapshot_version']);
        });
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->dropIndex(['environment']);
            $table->dropColumn('environment');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->unique('order_id');
        });

    }
};
