<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specs', function (Blueprint $table) {
            $table->timestamp('pr_merged_at')->nullable()->after('pr');
        });
    }

    public function down(): void
    {
        Schema::table('specs', function (Blueprint $table) {
            $table->dropColumn('pr_merged_at');
        });
    }
};
