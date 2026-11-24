<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('payments.tables.payments', 'payments');

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                // One charge = one `reference`; it can span several rows (one per product).
                $t->string('reference', 64)->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('product_id', 64)->nullable()->index();
                $t->unsignedBigInteger('payee_id')->nullable()->index();
                $t->string('gateway', 64)->index();
                $t->decimal('amount', 12, 2);
                $t->string('currency', 8)->default('LSL');
                $t->string('status', 20)->default('pending')->index();
                $t->string('msisdn')->nullable();
                $t->string('txn_id')->nullable()->index();           // provider transaction id
                $t->string('conversation_id')->nullable()->index();
                $t->string('description')->nullable();
                $t->json('meta')->nullable();
                $t->longText('raw_response')->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->timestamps();
            });

            return;
        }

        // Adopting an existing payments table: add missing columns, then backfill.
        Schema::table($table, function (Blueprint $t) use ($table) {
            if (! Schema::hasColumn($table, 'reference')) {
                $t->string('reference', 64)->nullable()->index();
            }
            if (! Schema::hasColumn($table, 'product_id')) {
                $t->string('product_id', 64)->nullable()->index();
            }
            if (! Schema::hasColumn($table, 'payee_id')) {
                $t->unsignedBigInteger('payee_id')->nullable()->index();
            }
            if (! Schema::hasColumn($table, 'gateway')) {
                $t->string('gateway', 64)->nullable()->index();
            }
            if (! Schema::hasColumn($table, 'currency')) {
                $t->string('currency', 8)->default('LSL');
            }
            if (! Schema::hasColumn($table, 'meta')) {
                $t->json('meta')->nullable();
            }
            if (! Schema::hasColumn($table, 'paid_at')) {
                $t->timestamp('paid_at')->nullable();
            }
        });

        // Backfill from legacy columns when present (Musik: type, music_id, service_id, seller_id).
        if (Schema::hasColumn($table, 'type')) {
            DB::table($table)->whereNull('gateway')->update(['gateway' => DB::raw('type')]);
        }
        if (Schema::hasColumn($table, 'txn_id')) {
            DB::table($table)->whereNull('reference')->update(['reference' => DB::raw('txn_id')]);
        }
        if (Schema::hasColumn($table, 'music_id') && Schema::hasColumn($table, 'service_id')) {
            DB::table($table)->whereNull('product_id')->update([
                'product_id' => DB::raw('COALESCE(music_id, service_id)'),
            ]);
        }
        if (Schema::hasColumn($table, 'seller_id')) {
            DB::table($table)->whereNull('payee_id')->update(['payee_id' => DB::raw('seller_id')]);
        }
        DB::table($table)->whereNull('paid_at')->where('status', 'completed')->update(['paid_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // Intentionally empty: this table may pre-date the package.
    }
};
