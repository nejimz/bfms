<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();

            $table->unique('name');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('category_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::dropIfExists('locations');
    }
};
