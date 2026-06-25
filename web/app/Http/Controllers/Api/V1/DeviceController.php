<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'max:40'],
            'device_fingerprint' => ['required', 'string', 'max:190'],
        ]);

        $token = Str::random(64);

        $device = Device::create([
            ...$data,
            'public_id' => 'dev_'.Str::lower(Str::random(16)),
            'token_hash' => hash('sha256', $token),
        ]);

        $device->policies()->create([
            'version' => 1,
            'rules' => [],
            'settings' => ['protection_enabled' => true, 'app_icon_visible' => false],
        ]);

        return response()->json([
            'device_id' => $device->public_id,
            'token' => $token,
            'policy_version' => 1,
        ], 201);
    }

    public function heartbeat(Request $request, Device $device): JsonResponse
    {
        if (! $this->isAuthorized($request, $device)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'policy_version' => ['nullable', 'integer', 'min:1'],
            'battery_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'vpn_active' => ['nullable', 'boolean'],
        ]);

        $device->forceFill([
            'last_seen_at' => now(),
            'last_policy_version' => $data['policy_version'] ?? $device->last_policy_version,
            'battery_percent' => $data['battery_percent'] ?? $device->battery_percent,
            'vpn_active' => $data['vpn_active'] ?? $device->vpn_active,
        ])->save();

        return response()->json([
            'device_id' => $device->public_id,
            'server_time' => now()->toISOString(),
        ]);
    }

    public function policy(Request $request, Device $device): JsonResponse
    {
        if (! $this->isAuthorized($request, $device)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $policy = $device->policies()->latest('version')->firstOrFail();
        $settings = [
            'protection_enabled' => true,
            'app_icon_visible' => true,
            'default_network' => 'allowed',
            ...($policy->settings ?? []),
        ];

        // Grouped in PHP rather than via a DB-specific aggregate (e.g. Postgres'
        // json_agg) so this works the same on SQLite (tests) and Postgres (Docker).
        $appDomains = \App\Models\AppDomainMapping::query()
            ->orderBy('domain')
            ->get(['app_package', 'domain'])
            ->groupBy('app_package')
            ->map(fn ($rows) => $rows->pluck('domain')->values())
            ->toArray();

        return response()->json([
            'device_id' => $device->public_id,
            'version' => $policy->version,
            'protection_enabled' => (bool) $settings['protection_enabled'],
            'settings' => $settings,
            'rules' => $settings['protection_enabled'] ? $policy->rules : [],
            'app_domains' => $appDomains,
        ]);
    }

    public function storeDomains(Request $request, Device $device): JsonResponse
    {
        if (! $this->isAuthorized($request, $device)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'domains' => ['required', 'array', 'max:200'],
            'domains.*' => ['required', 'string', 'max:255'],
            'app_package' => ['nullable', 'string', 'max:190'],
        ]);

        $now = now();
        $appPackage = $data['app_package'] ?? null;
        $inserted = 0;

        foreach ($data['domains'] as $domain) {
            $domain = strtolower(trim($domain));
            if (empty($domain)) continue;

            $existing = $device->domains()->where('domain', $domain)->first();
            if ($existing) {
                $existing->increment('seen_count');
                $existing->forceFill(['last_seen' => $now])->save();
                if ($appPackage && !$existing->app_package) {
                    $existing->forceFill(['app_package' => $appPackage])->save();
                }
            } else {
                $device->domains()->create([
                    'domain' => $domain,
                    'app_package' => $appPackage,
                    'seen_count' => 1,
                    'first_seen' => $now,
                    'last_seen' => $now,
                ]);
            }
            $inserted++;
        }

        return response()->json([
            'accepted' => true,
            'inserted' => $inserted,
        ], 202);
    }

    public function storeEvent(Request $request, Device $device): JsonResponse
    {
        if (! $this->isAuthorized($request, $device)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'type' => ['required', 'string', 'max:80'],
            'payload' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $device->events()->create([
            'type' => $data['type'],
            'payload' => $data['payload'] ?? [],
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        return response()->json(['accepted' => true], 202);
    }

    private function isAuthorized(Request $request, Device $device): bool
    {
        $token = $request->bearerToken();

        return is_string($token) && hash_equals($device->token_hash, hash('sha256', $token));
    }
}