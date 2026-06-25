<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(Request $request): View
    {
        $devices = Device::query()
            ->where(function ($query) use ($request) {
                $query->where('user_id', $request->user()->id)
                    ->orWhereNull('user_id');
            })
            ->withMax('policies', 'version')
            ->latest('updated_at')
            ->paginate(12);

        return view('devices.index', ['devices' => $devices]);
    }

    public function create(): View
    {
        return view('devices.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'in:android'],
            'device_fingerprint' => ['nullable', 'string', 'max:190'],
        ]);

        $token = Str::random(64);

        $device = Device::create([
            'user_id' => $request->user()->id,
            'public_id' => 'dev_'.Str::lower(Str::random(16)),
            'name' => $data['name'],
            'platform' => $data['platform'],
            'device_fingerprint' => $data['device_fingerprint'] ?: 'manual-'.Str::uuid(),
            'token_hash' => hash('sha256', $token),
        ]);

        $device->policies()->create([
            'version' => 1,
            'rules' => [],
            'settings' => ['protection_enabled' => true],
        ]);

        return redirect()
            ->route('devices.show', $device)
            ->with('device_token', $token)
            ->with('status', 'Smartphone registrado. Guarde o token de pareamento.');
    }

    public function claim(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === null, 404);

        $device->forceFill(['user_id' => $request->user()->id])->save();

        return redirect()
            ->route('devices.show', $device)
            ->with('status', 'Smartphone vinculado a sua conta.');
    }

    public function events(Request $request, Device $device): View
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $events = $device->events()
            ->latest('occurred_at')
            ->paginate(50);

        return view('devices.events', [
            'device' => $device,
            'events' => $events,
        ]);
    }

    public function show(Request $request, Device $device): View
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $device->load(['events' => fn ($query) => $query->latest('occurred_at')->limit(10)]);
        $policy = $device->latestPolicy();
        $settings = [
            'protection_enabled' => true,
            'app_icon_visible' => true,
            'default_network' => 'allowed',
            ...($policy?->settings ?? []),
        ];

        $lastPolicySync = $device->events()
            ->where('type', 'policy_applied')
            ->latest('occurred_at')
            ->first()
            ?->occurred_at;

        return view('devices.show', [
            'device' => $device,
            'policy' => $policy,
            'settings' => $settings,
            'rules' => collect($policy?->rules ?? []),
            'lastPolicySync' => $lastPolicySync,
            'policySyncIntervalMinutes' => 1,
        ]);
    }
}