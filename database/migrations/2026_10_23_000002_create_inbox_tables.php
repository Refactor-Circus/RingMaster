<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spec_id')->nullable()->constrained()->nullOnDelete();
            $table->string('spec_key')->nullable();
            $table->string('kind');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('ticket_ref')->nullable();
            $table->unsignedTinyInteger('priority')->default(1);
            $table->string('status')->default('queued');
            $table->string('claimed_by')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
        });

        Schema::create('attention_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spec_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('kind');
            $table->text('question');
            $table->json('options');
            $table->text('recommendation')->nullable();
            $table->text('evidence')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->default('open');
            $table->text('answer')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['spec_id', 'key']);
            $table->index('status');
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('spec_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('message');
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
            $table->index(['spec_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
        Schema::dropIfExists('attention_items');
        Schema::dropIfExists('tasks');
    }
};
