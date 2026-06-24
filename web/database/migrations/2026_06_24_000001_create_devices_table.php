<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('name');
            $table->string('platform', 40);
            $table->string('device_fingerprint')->index();
            $table->string('token_hash');
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('last_policy_version')->nullable();
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->boolean('vpn_active')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
