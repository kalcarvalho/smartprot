<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['device_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
    }
};
