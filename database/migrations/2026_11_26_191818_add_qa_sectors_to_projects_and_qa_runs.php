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
        foreach (['projects', 'qa_runs'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->json('qa_sectors')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['qa_runs', 'projects'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('qa_sectors');
            });
        }
    }
};
