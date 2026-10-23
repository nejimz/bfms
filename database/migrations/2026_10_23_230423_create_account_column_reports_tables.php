<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_column_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('account_column_report_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_column_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');

            $table->unique(['account_column_report_id', 'account_id'], 'account_column_report_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_column_report_account');
        Schema::dropIfExists('account_column_reports');
    }
};
