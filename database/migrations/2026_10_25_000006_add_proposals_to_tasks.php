<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('blocked_by_task_id')->nullable()->after('pause_reason')->constrained('tasks')->nullOnDelete();
            $table->string('proposal_reason', 2000)->nullable()->after('blocked_by_task_id');
            $table->string('resume_note', 2000)->nullable()->after('proposal_reason');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('blocked_by_task_id');
            $table->dropColumn(['proposal_reason', 'resume_note']);
        });
    }
};
