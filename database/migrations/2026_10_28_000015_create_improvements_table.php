<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('target');
            $table->text('rationale');
            $table->text('evidence');
            $table->text('change');
            $table->string('status')->default('open');
            $table->unsignedBigInteger('task_id')->nullable();
            $table->string('branch')->nullable();
            $table->json('results')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::table('runs', function (Blueprint $table) {
            $table->unsignedBigInteger('improvement_id')->nullable()->index();
            $table->string('replay_of')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropIndex(['improvement_id']);
            $table->dropColumn(['improvement_id', 'replay_of']);
        });
        Schema::dropIfExists('improvements');
    }
};
