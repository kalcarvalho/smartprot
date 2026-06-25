<?php

namespace Tests\Feature;

use App\Models\AppDomainMapping;
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
                'protection_enabled' => true,
                'rules' => [],
            ]);
    }

    public function test_disabled_protection_returns_empty_rules_to_device(): void
    {
        $device = Device::create([
            'public_id' => 'dev_paused',
            'name' => 'Child phone',
            'platform' => 'android',
            'device_fingerprint' => 'fingerprint-paused',
            'token_hash' => hash('sha256', 'secret-token'),
        ]);

        $device->policies()->create([
            'version' => 1,
            'settings' => ['protection_enabled' => false],
            'rules' => [[
                'id' => 'rule-1',
                'type' => 'domain',
                'target' => 'tiktok.com',
                'network' => 'blocked',
                'enabled' => true,
            ]],
        ]);

        $this->withToken('secret-token')
            ->getJson('/api/v1/devices/dev_paused/policy')
            ->assertOk()
            ->assertJson([
                'protection_enabled' => false,
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

        $device->policies()->create(['version' => 1, 'rules' => [], 'settings' => ['protection_enabled' => true]]);

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

    public function test_policy_response_includes_app_domains_mapping(): void
    {
        AppDomainMapping::create(['app_package' => 'org.telegram.messenger', 'domain' => 't.me']);
        AppDomainMapping::create(['app_package' => 'org.telegram.messenger', 'domain' => 'telegram.org']);
        AppDomainMapping::create(['app_package' => 'com.google.android.youtube', 'domain' => 'youtube.com']);

        $device = Device::create([
            'public_id' => 'dev_appdomains',
            'name' => 'Child phone',
            'platform' => 'android',
            'device_fingerprint' => 'fingerprint-appdomains',
            'token_hash' => hash('sha256', 'secret-token'),
        ]);
        $device->policies()->create(['version' => 1, 'rules' => [], 'settings' => ['protection_enabled' => true]]);

        $response = $this->withToken('secret-token')
            ->getJson('/api/v1/devices/dev_appdomains/policy')
            ->assertOk();

        $appDomains = $response->json('app_domains');

        $this->assertSame(['t.me', 'telegram.org'], $appDomains['org.telegram.messenger']);
        $this->assertSame(['youtube.com'], $appDomains['com.google.android.youtube']);
    }

    public function test_device_can_report_observed_domains(): void
    {
        $device = Device::create([
            'public_id' => 'dev_observe',
            'name' => 'Child phone',
            'platform' => 'android',
            'device_fingerprint' => 'fingerprint-observe',
            'token_hash' => hash('sha256', 'secret-token'),
        ]);
        $device->policies()->create(['version' => 1, 'rules' => [], 'settings' => ['protection_enabled' => true]]);

        $this->withToken('secret-token')
            ->postJson('/api/v1/devices/dev_observe/domains', [
                'domains' => ['t.me', 'telegram.org'],
                'app_package' => 'org.telegram.messenger',
            ])
            ->assertAccepted()
            ->assertJson(['accepted' => true, 'inserted' => 2]);

        $this->assertDatabaseHas('device_domains', [
            'device_id' => $device->id,
            'domain' => 't.me',
            'app_package' => 'org.telegram.messenger',
            'seen_count' => 1,
        ]);

        // Reporting the same domain again increments seen_count instead of duplicating.
        $this->withToken('secret-token')
            ->postJson('/api/v1/devices/dev_observe/domains', [
                'domains' => ['t.me'],
                'app_package' => 'org.telegram.messenger',
            ])
            ->assertAccepted();

        $this->assertDatabaseHas('device_domains', [
            'device_id' => $device->id,
            'domain' => 't.me',
            'seen_count' => 2,
        ]);
        $this->assertSame(1, $device->domains()->where('domain', 't.me')->count());
    }

    public function test_device_can_report_daily_usage_minutes(): void
    {
        $device = Device::create([
            'public_id' => 'dev_usage',
            'name' => 'Child phone',
            'platform' => 'android',
            'device_fingerprint' => 'fingerprint-usage',
            'token_hash' => hash('sha256', 'secret-token'),
        ]);
        $device->policies()->create(['version' => 1, 'rules' => [], 'settings' => ['protection_enabled' => true]]);

        $this->withToken('secret-token')
            ->postJson('/api/v1/devices/dev_usage/usage', [
                'usage' => ['rule-1' => 12, 'rule-2' => 45],
            ])
            ->assertAccepted()
            ->assertJson(['accepted' => true, 'updated' => 2]);

        $this->assertDatabaseHas('device_rule_usages', [
            'device_id' => $device->id,
            'rule_id' => 'rule-1',
            'minutes_used' => 12,
        ]);

        // Reporting again the same day updates the existing row instead of duplicating it.
        $this->withToken('secret-token')
            ->postJson('/api/v1/devices/dev_usage/usage', ['usage' => ['rule-1' => 13]])
            ->assertAccepted();

        $this->assertDatabaseHas('device_rule_usages', ['device_id' => $device->id, 'rule_id' => 'rule-1', 'minutes_used' => 13]);
        $this->assertSame(1, $device->ruleUsages()->where('rule_id', 'rule-1')->count());
    }
}