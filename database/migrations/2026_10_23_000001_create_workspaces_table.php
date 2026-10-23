<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('name');
            $table->boolean('from_config')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('error')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('folder');
            $table->string('name');
            $table->boolean('is_workspace')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'path']);
        });

        Schema::create('specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('dir');
            $table->string('title');
            $table->string('status');
            $table->string('column');
            $table->json('ticket')->nullable();
            $table->json('pr')->nullable();
            $table->string('branch')->nullable();
            $table->unsignedInteger('tasks_done')->default(0);
            $table->unsignedInteger('tasks_total')->default(0);
            $table->json('packages');
            $table->json('rounds');
            $table->unsignedInteger('decisions')->default(0);
            $table->string('decision')->nullable();
            $table->unsignedInteger('questions')->default(0);
            $table->json('files');
            $table->timestamp('spec_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'key']);
            $table->index('column');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specs');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('workspaces');
    }
};
