<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    /**
     * HeartbeatWorker runs every 15 minutes (Android WorkManager's periodic
     * minimum) — this must stay above that interval or devices flicker
     * offline between heartbeats.
     */
    public const ONLINE_THRESHOLD_MINUTES = 20;

    protected $fillable = [
        'user_id',
        'public_id',
        'name',
        'platform',
        'device_fingerprint',
        'token_hash',
        'last_seen_at',
        'last_policy_version',
        'battery_percent',
        'vpn_active',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'vpn_active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(Policy::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }

    public function latestPolicy(): ?Policy
    {
        return $this->policies()->latest('version')->first();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(DeviceDomain::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subMinutes(self::ONLINE_THRESHOLD_MINUTES));
    }
}
