<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_can_register_and_receive_initial_policy(): void
    {
        $register = $this->postJson('/api/v1/devices/register', [
            'name' => 'Child phone',
            'platform' => 'android',
            'device_fingerprint' => 'local-generated-id',
        ]);

        $register
            ->assertCreated()
            ->assertJsonStructure(['device_id', 'token', 'policy_version']);

        $deviceId = $register->json('device_id');
        $token = $register->json('token');

        $this->withToken($token)
            ->getJson("/api/v1/devices/{$deviceId}/policy")
            ->assertOk()
            ->assertJson([
                'device_id' => $deviceId,
                'version' => 1,
                'rules' => [],
            ]);
    }

    public function test_device_requires_valid_token_for_policy_sync(): void
    {
        $device = Device::create([
            'public_id' => 'dev_test',
            'name' => 'Child phone',
            'platform' => 'android',
            'device_fingerprint' => 'fingerprint',
            'token_hash' => hash('sha256', 'secret-token'),
        ]);

        $device->policies()->create(['version' => 1, 'rules' => []]);

        $this->getJson('/api/v1/devices/dev_test/policy')
            ->assertUnauthorized();
    }

    public function test_device_can_send_heartbeat_and_events(): void
    {
        $register = $this->postJson('/api/v1/devices/register', [
            'name' => 'Child phone',
            'platform' => 'android',
            'device_fingerprint' => 'local-generated-id',
        ]);

        $deviceId = $register->json('device_id');
        $token = $register->json('token');

        $this->withToken($token)
            ->postJson("/api/v1/devices/{$deviceId}/heartbeat", [
                'policy_version' => 1,
                'battery_percent' => 72,
                'vpn_active' => true,
            ])
            ->assertOk()
            ->assertJsonStructure(['device_id', 'server_time']);

        $this->withToken($token)
            ->postJson("/api/v1/devices/{$deviceId}/events", [
                'type' => 'traffic_blocked',
                'payload' => [
                    'rule_type' => 'domain',
                    'target' => 'tiktok.com',
                ],
            ])
            ->assertAccepted()
            ->assertJson(['accepted' => true]);

        $this->assertDatabaseHas('device_events', [
            'type' => 'traffic_blocked',
        ]);
    }
}
