<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('repository_url');
            $table->string('website_url', 2048);
            $table->string('target_mode')->default('public');
            $table->text('goal');
            $table->text('expected_behavior');
            $table->string('base_branch')->nullable();
            $table->string('deployed_sha', 64)->nullable();
            $table->string('repair_policy')->default('suggest_only');
            $table->json('connection')->nullable();
            $table->timestamps();
        });
        Schema::create('qa_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->text('goal');
            $table->text('expected_behavior');
            $table->string('base_sha', 64)->nullable();
            $table->json('patch_files')->nullable();
            $table->json('verification')->nullable();
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->string('pr_url', 2048)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('qa_runs')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('kind');
            $table->text('summary');
            $table->json('data')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'sequence']);
        });
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('qa_runs')->cascadeOnDelete();
            $table->string('title');
            $table->string('severity')->default('medium');
            $table->string('status')->default('observed');
            $table->text('expected');
            $table->text('actual');
            $table->json('evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['findings', 'run_steps', 'qa_runs', 'projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
