<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::rename('categories', 'classifications');

        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('category_id', 'classification_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('classification_id')->references('id')->on('classifications')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['classification_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('classification_id', 'category_id');
        });

        Schema::rename('classifications', 'categories');

        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });
    }
};
