<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specs', function (Blueprint $table) {
            $table->string('path', 1000)->nullable()->after('dir');
            $table->string('worktree', 1000)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('specs', function (Blueprint $table) {
            $table->dropColumn(['path', 'worktree']);
        });
    }
};
