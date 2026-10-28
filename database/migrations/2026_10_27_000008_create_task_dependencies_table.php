<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task can wait on any number of others, not just the one blocker it had:
 * a row per task and the task it waits on, removed once that one is done.
 */
return new class extends Migration
{
    /**
     * Dropping a column rebuilds the tasks table on SQLite with foreign keys
     * switched off, which SQLite ignores inside a transaction: the rebuild
     * would cascade-delete every run and activity row.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_task_id']);
            $table->index('depends_on_task_id');
        });

        $now = now();

        foreach (DB::table('tasks')->whereNotNull('blocked_by_task_id')->get(['id', 'blocked_by_task_id']) as $row) {
            DB::table('task_dependencies')->insert(['task_id' => $row->id, 'depends_on_task_id' => $row->blocked_by_task_id, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('blocked_by_task_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('blocked_by_task_id')->nullable()->after('pause_reason')->constrained('tasks')->nullOnDelete();
        });

        foreach (DB::table('task_dependencies')->orderBy('id')->get() as $row) {
            DB::table('tasks')->where('id', $row->task_id)->whereNull('blocked_by_task_id')->update(['blocked_by_task_id' => $row->depends_on_task_id]);
        }

        Schema::dropIfExists('task_dependencies');
    }
};
