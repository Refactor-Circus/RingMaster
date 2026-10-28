<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('key');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->json('payload');
            $table->string('status')->default('open');
            $table->text('result')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'key']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chores');
    }
};
