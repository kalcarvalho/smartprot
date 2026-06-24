<?php

namespace Tests\Feature;

use App\Models\AppDomainMapping;
use App\Models\Device;
use App\Models\DeviceDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceDomainManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_observed_domains_for_own_device(): void
    {
        $user = User::factory()->create();
        $device = $this->deviceFor($user);
        $device->domains()->create([
            'domain' => 't.me',
            'app_package' => null,
            'seen_count' => 3,
            'first_seen' => now(),
            'last_seen' => now(),
        ]);

        $this->actingAs($user)
            ->get("/devices/{$device->id}/domains")
            ->assertOk()
            ->assertSee('t.me');
    }

    public function test_user_cannot_view_domains_of_another_users_device(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $device = $this->deviceFor($owner, 'dev_private_domains');

        $this->actingAs($other)
            ->get("/devices/{$device->id}/domains")
            ->assertNotFound();
    }

    public function test_user_can_associate_observed_domain_with_an_app(): void
    {
        $user = User::factory()->create();
        $device = $this->deviceFor($user);
        $domain = $device->domains()->create([
            'domain' => 't.me',
            'app_package' => null,
            'seen_count' => 1,
            'first_seen' => now(),
            'last_seen' => now(),
        ]);

        $this->actingAs($user)
            ->patch("/devices/{$device->id}/domains/{$domain->id}", [
                'app_package' => 'org.telegram.messenger',
            ])
            ->assertRedirect();

        $this->assertSame('org.telegram.messenger', $domain->fresh()->app_package);

        // Associating creates a global mapping so other devices get this domain
        // back dynamically the next time they sync their policy.
        $this->assertDatabaseHas('app_domain_mappings', [
            'app_package' => 'org.telegram.messenger',
            'domain' => 't.me',
        ]);
    }

    public function test_associating_a_domain_from_another_device_is_rejected(): void
    {
        $user = User::factory()->create();
        $deviceA = $this->deviceFor($user, 'dev_a');
        $deviceB = $this->deviceFor($user, 'dev_b');
        $domain = $deviceB->domains()->create([
            'domain' => 't.me',
            'seen_count' => 1,
            'first_seen' => now(),
            'last_seen' => now(),
        ]);

        $this->actingAs($user)
            ->patch("/devices/{$deviceA->id}/domains/{$domain->id}", [
                'app_package' => 'org.telegram.messenger',
            ])
            ->assertNotFound();
    }

    public function test_user_can_manage_global_app_domain_mappings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/app-domain-mappings')
            ->assertOk();

        $this->actingAs($user)
            ->post('/app-domain-mappings', [
                'app_package' => 'org.telegram.messenger',
                'domain' => 'Telegram.org',
            ])
            ->assertRedirect();

        $mapping = AppDomainMapping::firstOrFail();
        $this->assertSame('telegram.org', $mapping->domain);
        $this->assertSame($user->id, $mapping->user_id);

        $this->actingAs($user)
            ->delete("/app-domain-mappings/{$mapping->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('app_domain_mappings', ['id' => $mapping->id]);
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
