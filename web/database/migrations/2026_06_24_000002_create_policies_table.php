<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('rules');
            $table->timestamps();

            $table->unique(['device_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
