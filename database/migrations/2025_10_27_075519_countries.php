<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('capital')->nullable();
            $table->string('region')->nullable();
            $table->bigInteger('population');
            $table->string('currency_code')->nullable();
            $table->decimal('exchange_rate', 15, 4)->nullable();
            $table->decimal('estimated_gdp', 20, 2)->nullable();
            $table->string('flag_url')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
