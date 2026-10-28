<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('intent');
            $table->string('status')->default('running');
            $table->timestamps();
        });

        // A plain column: adding a constrained one makes SQLite rebuild tasks, which cascades.
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['campaign_id']);
        });
        Schema::dropIfExists('campaigns');
    }
};
