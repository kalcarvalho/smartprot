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
        $this->assertTrue($device->policies()->first()->protectionEnabled());
    }

    public function test_user_can_add_and_remove_blocking_rule(): void
    {
        $user = User::factory()->create();
        $device = $this->deviceFor($user);
        $device->policies()->create(['version' => 1, 'rules' => [], 'settings' => ['protection_enabled' => true]]);

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
        $this->assertTrue($policy->rules[0]['enabled']);

        $ruleId = $policy->rules[0]['id'];

        $this->actingAs($user)
            ->delete("/devices/{$device->id}/rules/{$ruleId}")
            ->assertRedirect();

        $policy = $device->fresh()->latestPolicy();
        $this->assertSame(3, $policy->version);
        $this->assertSame([], $policy->rules);
    }

    public function test_user_can_create_scheduled_rule_with_daily_limit(): void
    {
        $user = User::factory()->create();
        $device = $this->deviceFor($user);
        $device->policies()->create(['version' => 1, 'rules' => [], 'settings' => ['protection_enabled' => true]]);

        $this->actingAs($user)
            ->post("/devices/{$device->id}/rules", [
                'type' => 'app',
                'target' => 'com.google.android.youtube',
                'network' => 'blocked',
                'enabled' => '1',
                'schedule_enabled' => '1',
                'schedule_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                'starts_at' => '08:00',
                'ends_at' => '18:00',
                'daily_limit_minutes' => 45,
            ])
            ->assertRedirect();

        $rule = $device->fresh()->latestPolicy()->rules[0];

        $this->assertSame('app', $rule['type']);
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri'], $rule['schedule']['days']);
        $this->assertSame('08:00', $rule['schedule']['starts_at']);
        $this->assertSame('18:00', $rule['schedule']['ends_at']);
        $this->assertSame(45, $rule['daily_limit_minutes']);
    }

    public function test_device_show_page_renders_with_a_scheduled_rule(): void
    {
        $user = User::factory()->create();
        $device = $this->deviceFor($user);
        $device->policies()->create([
            'version' => 1,
            'settings' => ['protection_enabled' => true],
            'rules' => [[
                'id' => 'rule-1',
                'type' => 'domain',
                'target' => 'tiktok.com',
                'network' => 'blocked',
                'enabled' => true,
                'schedule' => ['days' => ['mon', 'tue'], 'starts_at' => '08:00', 'ends_at' => '18:00'],
                'daily_limit_minutes' => 30,
                'notes' => 'School hours',
            ]],
        ]);

        $this->actingAs($user)
            ->get("/devices/{$device->id}")
            ->assertOk()
            ->assertSee('tiktok.com');
    }

    public function test_user_can_pause_device_protection(): void
    {
        $user = User::factory()->create();
        $device = $this->deviceFor($user);
        $device->policies()->create([
            'version' => 1,
            'settings' => ['protection_enabled' => true],
            'rules' => [[
                'id' => 'rule-1',
                'type' => 'domain',
                'target' => 'tiktok.com',
                'network' => 'blocked',
                'enabled' => true,
            ]],
        ]);

        $this->actingAs($user)
            ->patch("/devices/{$device->id}/protection", ['protection_enabled' => '0'])
            ->assertRedirect();

        $policy = $device->fresh()->latestPolicy();
        $this->assertSame(2, $policy->version);
        $this->assertFalse($policy->protectionEnabled());
        $this->assertCount(1, $policy->rules);
    }

    public function test_user_can_edit_an_existing_rule(): void
    {
        $user = User::factory()->create();
        $device = $this->deviceFor($user);
        $device->policies()->create([
            'version' => 1,
            'settings' => ['protection_enabled' => true],
            'rules' => [[
                'id' => 'rule-1',
                'type' => 'domain',
                'target' => 'tiktok.com',
                'network' => 'blocked',
                'enabled' => true,
                'schedule' => null,
                'daily_limit_minutes' => null,
                'notes' => null,
                'created_at' => now()->toISOString(),
            ]],
        ]);

        $this->actingAs($user)
            ->put("/devices/{$device->id}/rules/rule-1", [
                'type' => 'domain',
                'target' => 'instagram.com',
                'network' => 'allowed',
                'enabled' => '1',
            ])
            ->assertRedirect();

        $rule = $device->fresh()->latestPolicy()->rules[0];
        $this->assertSame('rule-1', $rule['id']);
        $this->assertSame('instagram.com', $rule['target']);
        $this->assertSame('allowed', $rule['network']);
    }

    public function test_user_can_set_default_network_policy(): void
    {
        $user = User::factory()->create();
        $device = $this->deviceFor($user);
        $device->policies()->create(['version' => 1, 'rules' => [], 'settings' => ['protection_enabled' => true]]);

        $this->actingAs($user)
            ->patch("/devices/{$device->id}/default-network", ['default_network' => 'blocked'])
            ->assertRedirect();

        $policy = $device->fresh()->latestPolicy();
        $this->assertSame(2, $policy->version);
        $this->assertSame('blocked', $policy->settings['default_network']);
    }

    public function test_user_cannot_view_another_users_device(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $device = $this->deviceFor($owner, 'dev_private', 'Private phone');

        $this->actingAs($other)
            ->get("/devices/{$device->id}")
            ->assertNotFound();
    }

    private function deviceFor(User $user, string $publicId = 'dev_test', string $name = 'Child phone'): Device
    {
        return Device::create([
            'user_id' => $user->id,
            'public_id' => $publicId,
            'name' => $name,
            'platform' => 'android',
            'device_fingerprint' => 'fingerprint-'.$publicId,
            'token_hash' => hash('sha256', 'secret-token'),
        ]);
    }
}