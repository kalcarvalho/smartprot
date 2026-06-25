<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_rule_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('rule_id', 190);
            $table->date('usage_date');
            $table->unsignedInteger('minutes_used')->default(0);
            $table->timestamps();
            $table->unique(['device_id', 'rule_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_rule_usages');
    }
};
