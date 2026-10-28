<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('key');
            $table->string('title');
            $table->string('url')->nullable();
            $table->text('detail')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['project_id', 'key']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
