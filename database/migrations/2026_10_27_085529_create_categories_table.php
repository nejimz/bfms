<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classifications', function (Blueprint $table) {
            $table->renameIndex('categories_name_unique', 'classifications_name_unique');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();

            $table->unique('name');
        });

        Schema::table('classifications', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('name')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('categories');

        Schema::table('classifications', function (Blueprint $table) {
            $table->renameIndex('classifications_name_unique', 'categories_name_unique');
        });
    }
};
