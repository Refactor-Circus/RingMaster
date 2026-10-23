<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('session');
            $table->string('status')->default('running');
            $table->unsignedInteger('pid')->nullable();
            $table->string('log_path');
            $table->integer('exit_code')->nullable();
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });

        Schema::dropIfExists('runs');
    }
};
