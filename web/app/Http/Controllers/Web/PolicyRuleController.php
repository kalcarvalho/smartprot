<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PolicyRuleController extends Controller
{
    private const DEFAULT_SETTINGS = ['protection_enabled' => true];

    public function store(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'type' => ['required', 'in:app,domain,ip,url'],
            'target' => ['required', 'string', 'max:255'],
            'network' => ['required', 'in:blocked,allowed'],
            'notes' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
            'schedule_enabled' => ['nullable', 'boolean'],
            'schedule_days' => ['nullable', 'array'],
            'schedule_days.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
            'starts_at' => ['nullable', 'date_format:H:i', 'required_with:ends_at'],
            'ends_at' => ['nullable', 'date_format:H:i', 'required_with:starts_at'],
            'daily_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $policy = $device->latestPolicy();
        $rules = collect($policy?->rules ?? [])->push([
            'id' => (string) Str::uuid(),
            'type' => $data['type'],
            'target' => trim($data['target']),
            'network' => $data['network'],
            'enabled' => $request->boolean('enabled', true),
            'schedule' => $this->scheduleFrom($data, $request),
            'daily_limit_minutes' => $data['daily_limit_minutes'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_at' => now()->toISOString(),
        ])->values()->all();

        $this->createPolicyVersion($device, $policy, $rules, $this->settingsFrom($policy));

        return back()->with('status', 'Regra adicionada e politica versionada.');
    }

    public function update(Request $request, Device $device, string $ruleId): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $policy = $device->latestPolicy();
        $rules = collect($policy?->rules ?? [])
            ->map(function (array $rule) use ($ruleId, $data): array {
                if (($rule['id'] ?? null) === $ruleId) {
                    $rule['enabled'] = (bool) $data['enabled'];
                }

                return $rule;
            })
            ->values()
            ->all();

        $this->createPolicyVersion($device, $policy, $rules, $this->settingsFrom($policy));

        return back()->with('status', 'Status da regra atualizado.');
    }

    public function destroy(Request $request, Device $device, string $ruleId): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $policy = $device->latestPolicy();
        $rules = collect($policy?->rules ?? [])
            ->reject(fn (array $rule): bool => ($rule['id'] ?? null) === $ruleId)
            ->values()
            ->all();

        $this->createPolicyVersion($device, $policy, $rules, $this->settingsFrom($policy));

        return back()->with('status', 'Regra removida e politica versionada.');
    }

    public function toggleProtection(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'protection_enabled' => ['required', 'boolean'],
        ]);

        $policy = $device->latestPolicy();
        $settings = [
            ...$this->settingsFrom($policy),
            'protection_enabled' => (bool) $data['protection_enabled'],
        ];

        $this->createPolicyVersion($device, $policy, $policy?->rules ?? [], $settings);

        return back()->with('status', $settings['protection_enabled'] ? 'Bloqueios ativados.' : 'Bloqueios desativados.');
    }

    private function scheduleFrom(array $data, Request $request): ?array
    {
        if (! $request->boolean('schedule_enabled')) {
            return null;
        }

        return [
            'days' => array_values($data['schedule_days'] ?? []),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'timezone' => config('app.timezone'),
        ];
    }

    private function settingsFrom(?Policy $policy): array
    {
        return [
            ...self::DEFAULT_SETTINGS,
            ...($policy?->settings ?? []),
        ];
    }

    private function createPolicyVersion(Device $device, ?Policy $policy, array $rules, array $settings): void
    {
        $nextVersion = ($policy?->version ?? 0) + 1;

        $device->policies()->create([
            'version' => $nextVersion,
            'rules' => $rules,
            'settings' => $settings,
        ]);

        $device->forceFill(['last_policy_version' => $nextVersion])->save();
    }
}