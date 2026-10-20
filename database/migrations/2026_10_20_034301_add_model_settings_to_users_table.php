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
        Schema::table('users', function (Blueprint $table) {
            $table->string('model_provider', 20)->default('local');
            $table->string('model_name', 150)->nullable();
            $table->string('model_base_url', 2048)->nullable();
            $table->text('model_api_key')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['model_provider', 'model_name', 'model_base_url', 'model_api_key']);
        });
    }
};
