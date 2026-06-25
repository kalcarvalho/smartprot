<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceRuleUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'rule_id',
        'usage_date',
        'minutes_used',
    ];

    protected function casts(): array
    {
        return [
            'minutes_used' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
