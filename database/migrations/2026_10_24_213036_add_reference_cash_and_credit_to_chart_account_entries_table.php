<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_account_entries', function (Blueprint $table) {
            $table->string('reference', 50)->nullable()->after('particulars');
            $table->bigInteger('cash_cents')->default(0)->after('amount_cents');
            $table->bigInteger('credit_cents')->default(0)->after('cash_cents');
        });

        DB::table('chart_account_entries')->update([
            'cash_cents' => DB::raw('amount_cents'),
        ]);
    }

    public function down(): void
    {
        Schema::table('chart_account_entries', function (Blueprint $table) {
            $table->dropColumn(['reference', 'cash_cents', 'credit_cents']);
        });
    }
};
