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

        return response()->json([
            'device_id' => $device->public_id,
            'version' => $policy->version,
            'rules' => $policy->rules,
        ]);
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
