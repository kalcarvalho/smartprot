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
    private const DEFAULT_SETTINGS = [
        'protection_enabled' => true,
        'app_icon_visible' => true,
        'default_network' => 'allowed',
    ];

    private function validateRuleInput(Request $request): array
    {
        return $request->validate([
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
    }

    public function store(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $data = $this->validateRuleInput($request);

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

    public function editRule(Request $request, Device $device, string $ruleId): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $data = $this->validateRuleInput($request);

        $policy = $device->latestPolicy();
        $found = false;
        $rules = collect($policy?->rules ?? [])
            ->map(function (array $rule) use ($ruleId, $data, $request, &$found): array {
                if (($rule['id'] ?? null) !== $ruleId) {
                    return $rule;
                }

                $found = true;

                return [
                    'id' => $rule['id'],
                    'type' => $data['type'],
                    'target' => trim($data['target']),
                    'network' => $data['network'],
                    'enabled' => $request->boolean('enabled', true),
                    'schedule' => $this->scheduleFrom($data, $request),
                    'daily_limit_minutes' => $data['daily_limit_minutes'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_at' => $rule['created_at'] ?? now()->toISOString(),
                ];
            })
            ->values()
            ->all();

        abort_unless($found, 404);

        $this->createPolicyVersion($device, $policy, $rules, $this->settingsFrom($policy));

        return back()->with('status', 'Regra atualizada e politica versionada.');
    }

    public function duplicateRule(Request $request, Device $device, string $ruleId): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $policy = $device->latestPolicy();
        $original = collect($policy?->rules ?? [])->firstWhere('id', $ruleId);
        abort_unless($original !== null, 404);

        $copy = $original;
        $copy['id'] = (string) Str::uuid();
        $copy['created_at'] = now()->toISOString();
        $copy['notes'] = trim(($original['notes'] ?? '').' (copia)');

        $rules = collect($policy->rules)->push($copy)->values()->all();

        $this->createPolicyVersion($device, $policy, $rules, $this->settingsFrom($policy));

        return back()->with('status', 'Regra duplicada. Edite a copia para ajustar.');
    }

    public function copyRuleToDevice(Request $request, Device $device, string $ruleId): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'target_device_id' => ['required', 'integer', 'exists:devices,id'],
        ]);

        abort_if((int) $data['target_device_id'] === $device->id, 422, 'Selecione um aparelho diferente.');

        $targetDevice = Device::findOrFail($data['target_device_id']);
        abort_unless($targetDevice->user_id === null || $targetDevice->user_id === $request->user()->id, 404);

        $policy = $device->latestPolicy();
        $original = collect($policy?->rules ?? [])->firstWhere('id', $ruleId);
        abort_unless($original !== null, 404);

        $copy = $original;
        $copy['id'] = (string) Str::uuid();
        $copy['created_at'] = now()->toISOString();

        $targetPolicy = $targetDevice->latestPolicy();
        $targetRules = collect($targetPolicy?->rules ?? [])->push($copy)->values()->all();

        $this->createPolicyVersion($targetDevice, $targetPolicy, $targetRules, $this->settingsFrom($targetPolicy));

        return back()->with('status', "Regra copiada para {$targetDevice->name}.");
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

    public function toggleIconVisibility(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'app_icon_visible' => ['required', 'boolean'],
        ]);

        $policy = $device->latestPolicy();
        $settings = [
            ...$this->settingsFrom($policy),
            'app_icon_visible' => (bool) $data['app_icon_visible'],
        ];

        $this->createPolicyVersion($device, $policy, $policy?->rules ?? [], $settings);

        return back()->with('status', $settings['app_icon_visible'] ? 'Icone do app reativado.' : 'Icone do app ocultado.');
    }

    public function toggleDefaultNetwork(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'default_network' => ['required', 'in:blocked,allowed'],
        ]);

        $policy = $device->latestPolicy();
        $settings = [
            ...$this->settingsFrom($policy),
            'default_network' => $data['default_network'],
        ];

        $this->createPolicyVersion($device, $policy, $policy?->rules ?? [], $settings);

        return back()->with('status', $settings['default_network'] === 'blocked'
            ? 'Politica padrao: bloquear tudo (regras de liberacao abaixo sao exececoes).'
            : 'Politica padrao: liberar tudo (regras de bloqueio abaixo sao exececoes).');
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