<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('payments.tables.gateways', 'payment_gateways');

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('slug')->unique();
                $t->string('driver')->nullable();
                $t->boolean('enabled')->default(true);
                $t->string('mode')->nullable();          // sandbox | live ; null = use config default
                $t->string('currency', 8)->nullable();
                $t->text('credentials')->nullable();     // encrypted JSON
                $t->json('settings')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });

            return;
        }

        // Adopting an existing table (e.g. Musik's name/slug/enabled): only add what is missing.
        Schema::table($table, function (Blueprint $t) use ($table) {
            if (! Schema::hasColumn($table, 'driver')) {
                $t->string('driver')->nullable();
            }
            if (! Schema::hasColumn($table, 'mode')) {
                $t->string('mode')->nullable();
            }
            if (! Schema::hasColumn($table, 'currency')) {
                $t->string('currency', 8)->nullable();
            }
            if (! Schema::hasColumn($table, 'credentials')) {
                $t->text('credentials')->nullable();
            }
            if (! Schema::hasColumn($table, 'settings')) {
                $t->json('settings')->nullable();
            }
            if (! Schema::hasColumn($table, 'sort_order')) {
                $t->unsignedInteger('sort_order')->default(0);
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: this table may pre-date the package.
    }
};
