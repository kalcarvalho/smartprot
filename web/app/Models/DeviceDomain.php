<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'domain',
        'app_package',
        'seen_count',
        'first_seen',
        'last_seen',
    ];

    protected function casts(): array
    {
        return [
            'seen_count' => 'integer',
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
