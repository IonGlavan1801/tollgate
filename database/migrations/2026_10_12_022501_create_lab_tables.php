<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at')->index();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('virtual_now');
            $t->timestamps();
        });
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained();
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('interval');
            $t->unique(['plan_id', 'currency', 'interval']);
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('demo_session_id')->constrained();
            $t->string('display_name');
            $t->string('country', 2);
            $t->timestamps();
        });
        Schema::create('provider_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('environment');
            $t->string('status');
            $t->unique(['provider', 'environment']);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('demo_session_id')->constrained();
            $t->foreignUuid('customer_id')->constrained();
            $t->foreignId('price_id')->constrained();
            $t->string('provider');
            $t->string('environment')->default('simulated');
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('status')->default('pending');
            $t->unsignedInteger('version')->default(0);
            $t->uuid('correlation_id');
            $t->timestamps();
            $t->index(['demo_session_id', 'created_at']);
        });
        Schema::create('checkout_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained();
            $t->string('status');
            $t->string('external_id')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_intents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->unique()->constrained();
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->unique()->constrained();
            $t->string('provider');
            $t->string('environment');
            $t->string('external_id');
            $t->bigInteger('amount_minor');
            $t->bigInteger('refunded_minor')->default(0);
            $t->char('currency', 3);
            $t->string('status');
            $t->timestamps();
            $t->unique(['provider', 'environment', 'external_id']);
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('payment_id')->constrained();
            $t->string('idempotency_key')->unique();
            $t->bigInteger('amount_minor');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('disputes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('payment_id')->unique()->constrained();
            $t->string('status');
            $t->bigInteger('amount_minor');
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->unique()->constrained();
            $t->foreignUuid('demo_session_id')->constrained();
            $t->foreignId('price_id')->constrained();
            $t->string('provider');
            $t->string('environment');
            $t->string('status');
            $t->unsignedInteger('version')->default(0);
            $t->boolean('cancel_at_period_end')->default(false);
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->timestamps();
        });
        Schema::create('subscription_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('subscription_id')->constrained();
            $t->foreignId('price_id')->constrained();
            $t->unsignedInteger('subscription_version');
            $t->bigInteger('proration_minor');
            $t->string('status');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('provider_objects', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('environment');
            $t->string('external_id');
            $t->uuid('internal_id');
            $t->string('object_type');
            $t->unique(['provider', 'environment', 'external_id']);
            $t->timestamps();
        });
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('demo_session_id')->constrained();
            $t->foreignUuid('order_id')->constrained();
            $t->string('provider');
            $t->string('environment');
            $t->string('external_event_id');
            $t->string('type');
            $t->string('payload_hash', 64);
            $t->json('payload');
            $t->string('status')->default('persisted');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->index();
            $t->uuid('correlation_id');
            $t->timestamps();
            $t->unique(['provider', 'environment', 'external_event_id']);
            $t->index(['status', 'available_at']);
        });
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->nullable()->references('id')->on('webhook_events');
            $t->foreignUuid('demo_session_id')->nullable()->constrained();
            $t->string('provider');
            $t->string('environment');
            $t->string('payload_hash', 64);
            $t->string('verdict');
            $t->unsignedInteger('duration_us');
            $t->timestamps();
        });
        Schema::create('webhook_processing_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('event_id')->constrained('webhook_events');
            $t->unsignedInteger('number');
            $t->string('result');
            $t->timestamps();
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('demo_session_id')->constrained();
            $t->string('key');
            $t->string('request_hash', 64);
            $t->uuid('resource_id');
            $t->string('operation');
            $t->timestamps();
            $t->unique(['demo_session_id', 'operation', 'key']);
        });
        Schema::create('ledger_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('ledger_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('demo_session_id')->constrained();
            $t->string('reference')->unique();
            $t->char('currency', 3);
            $t->string('description');
            $t->uuid('reversal_of')->nullable();
            $t->timestamps();
        });
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('transaction_id')->constrained('ledger_transactions');
            $t->foreignId('account_id')->constrained('ledger_accounts');
            $t->bigInteger('debit_minor')->default(0);
            $t->bigInteger('credit_minor')->default(0);
            $t->timestamps();
        });
        Schema::create('tax_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('order_id')->constrained();
            $t->string('country', 2);
            $t->unsignedInteger('rate_bps');
            $t->bigInteger('tax_minor');
            $t->string('decision');
            $t->string('source');
            $t->timestamps();
        });
        Schema::create('vat_validations', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('demo_session_id')->constrained();
            $t->string('status');
            $t->string('source');
            $t->timestamp('checked_at');
            $t->timestamps();
        });
        Schema::create('invoice_number_sequences', function (Blueprint $t) {
            $t->string('series')->primary();
            $t->unsignedBigInteger('next_number')->default(1);
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained();
            $t->string('reference')->unique();
            $t->string('number')->nullable()->unique();
            $t->string('status');
            $t->string('kind');
            $t->bigInteger('total_minor');
            $t->char('currency', 3);
            $t->timestamp('issued_at')->nullable();
            $t->timestamps();
        });
        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('invoice_id')->constrained();
            $t->string('description');
            $t->bigInteger('amount_minor');
            $t->timestamps();
        });
        Schema::create('dunning_cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('subscription_id')->constrained();
            $t->string('status');
            $t->timestamp('started_at');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps();
        });
        Schema::create('dunning_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('case_id')->constrained('dunning_cases');
            $t->unsignedInteger('number');
            $t->timestamp('due_at');
            $t->string('status');
            $t->unique(['case_id', 'number']);
            $t->timestamps();
        });
        Schema::create('outbox_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('demo_session_id')->constrained();
            $t->string('dedup_key')->unique();
            $t->string('type');
            $t->json('payload');
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->index();
            $t->timestamps();
        });
        Schema::create('demo_mail', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('outbox_id')->unique()->constrained('outbox_messages');
            $t->foreignUuid('demo_session_id')->constrained();
            $t->string('subject');
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('demo_session_id')->constrained();
            $t->uuid('correlation_id');
            $t->string('action');
            $t->string('status');
            $t->json('details');
            $t->timestamps();
            $t->index(['demo_session_id', 'id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE ledger_transactions ADD COLUMN posting_xid bigint NOT NULL DEFAULT txid_current();
                ALTER TABLE orders ADD CONSTRAINT orders_positive CHECK (amount_minor > 0);
                ALTER TABLE payments ADD CONSTRAINT payment_amounts CHECK (amount_minor > 0 AND refunded_minor >= 0 AND refunded_minor <= amount_minor);
                ALTER TABLE refunds ADD CONSTRAINT refunds_positive CHECK (amount_minor > 0);
                ALTER TABLE ledger_entries ADD CONSTRAINT entry_one_side CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0));
                CREATE OR REPLACE FUNCTION reject_ledger_mutation() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'Ledger is append-only'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER ledger_entries_immutable BEFORE UPDATE OR DELETE ON ledger_entries FOR EACH ROW EXECUTE FUNCTION reject_ledger_mutation();
                CREATE TRIGGER ledger_transactions_immutable BEFORE UPDATE OR DELETE ON ledger_transactions FOR EACH ROW EXECUTE FUNCTION reject_ledger_mutation();
                CREATE OR REPLACE FUNCTION check_ledger_balance() RETURNS trigger AS $$
                DECLARE tx uuid; balance numeric; entries integer;
                BEGIN
                    IF TG_TABLE_NAME = 'ledger_transactions' THEN tx := NEW.id; ELSE tx := NEW.transaction_id; END IF;
                    SELECT COALESCE(SUM(debit_minor-credit_minor),0), COUNT(*) INTO balance, entries FROM ledger_entries WHERE transaction_id=tx;
                    IF balance <> 0 OR entries < 2 THEN RAISE EXCEPTION 'Unbalanced ledger transaction'; END IF;
                    RETURN NULL;
                END; $$ LANGUAGE plpgsql;
                CREATE CONSTRAINT TRIGGER ledger_balance_header AFTER INSERT ON ledger_transactions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_ledger_balance();
                CREATE CONSTRAINT TRIGGER ledger_balance_entries AFTER INSERT ON ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_ledger_balance();
                CREATE OR REPLACE FUNCTION reject_posted_entry() RETURNS trigger AS $$
                BEGIN
                    IF EXISTS(SELECT 1 FROM ledger_transactions WHERE id=NEW.transaction_id AND posting_xid <> txid_current()) THEN RAISE EXCEPTION 'Cannot append to posted transaction'; END IF;
                    RETURN NEW;
                END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER ledger_closed BEFORE INSERT ON ledger_entries FOR EACH ROW EXECUTE FUNCTION reject_posted_entry();
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['audit_events', 'demo_mail', 'outbox_messages', 'dunning_attempts', 'dunning_cases', 'invoice_lines', 'invoices', 'invoice_number_sequences', 'vat_validations', 'tax_evidence', 'ledger_entries', 'ledger_transactions', 'ledger_accounts', 'idempotency_keys', 'webhook_processing_attempts', 'webhook_deliveries', 'webhook_events', 'provider_objects', 'subscription_changes', 'subscriptions', 'disputes', 'refunds', 'payments', 'payment_intents', 'checkout_attempts', 'orders', 'provider_accounts', 'customers', 'prices', 'plans', 'demo_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_ledger_mutation(), check_ledger_balance(), reject_posted_entry()');
        }
    }
};
