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
        Schema::table('qa_runs', function (Blueprint $table) {
            $table->boolean('advanced')->default(false);
            $table->text('guidance')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('qa_runs', function (Blueprint $table) {
            $table->dropColumn(['advanced', 'guidance']);
        });
    }
};
