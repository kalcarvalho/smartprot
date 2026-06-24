<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_a_smartphone_from_panel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/devices', [
            'name' => 'Child phone',
            'platform' => 'android',
            'device_fingerprint' => 'manual-test-device',
        ]);

        $device = Device::firstOrFail();

        $response
            ->assertRedirect(route('devices.show', $device))
            ->assertSessionHas('device_token');

        $this->assertSame($user->id, $device->user_id);
        $this->assertSame(1, $device->policies()->first()->version);
    }

    public function test_user_can_add_and_remove_blocking_rule(): void
    {
        $user = User::factory()->create();
        $device = Device::create([
            'user_id' => $user->id,
            'public_id' => 'dev_test',
            'name' => 'Child phone',
            'platform' => 'android',
            'device_fingerprint' => 'fingerprint',
            'token_hash' => hash('sha256', 'secret-token'),
        ]);
        $device->policies()->create(['version' => 1, 'rules' => []]);

        $this->actingAs($user)
            ->post("/devices/{$device->id}/rules", [
                'type' => 'domain',
                'target' => 'tiktok.com',
                'network' => 'blocked',
                'notes' => 'School hours',
            ])
            ->assertRedirect();

        $policy = $device->fresh()->latestPolicy();
        $this->assertSame(2, $policy->version);
        $this->assertSame('tiktok.com', $policy->rules[0]['target']);

        $ruleId = $policy->rules[0]['id'];

        $this->actingAs($user)
            ->delete("/devices/{$device->id}/rules/{$ruleId}")
            ->assertRedirect();

        $policy = $device->fresh()->latestPolicy();
        $this->assertSame(3, $policy->version);
        $this->assertSame([], $policy->rules);
    }

    public function test_user_cannot_view_another_users_device(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $device = Device::create([
            'user_id' => $owner->id,
            'public_id' => 'dev_private',
            'name' => 'Private phone',
            'platform' => 'android',
            'device_fingerprint' => 'private',
            'token_hash' => hash('sha256', 'secret-token'),
        ]);

        $this->actingAs($other)
            ->get("/devices/{$device->id}")
            ->assertNotFound();
    }
}
