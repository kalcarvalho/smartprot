<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PolicyRuleController extends Controller
{
    public function store(Request $request, Device $device): RedirectResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'type' => ['required', 'in:app,domain,ip,url'],
            'target' => ['required', 'string', 'max:255'],
            'network' => ['required', 'in:blocked,allowed'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $policy = $device->latestPolicy();
        $rules = collect($policy?->rules ?? [])->push([
            'id' => (string) Str::uuid(),
            'type' => $data['type'],
            'target' => trim($data['target']),
            'network' => $data['network'],
            'notes' => $data['notes'] ?? null,
            'created_at' => now()->toISOString(),
        ])->values()->all();

        $nextVersion = ($policy?->version ?? 0) + 1;

        $device->policies()->create([
            'version' => $nextVersion,
            'rules' => $rules,
        ]);

        $device->forceFill(['last_policy_version' => $nextVersion])->save();

        return back()->with('status', 'Regra adicionada e politica versionada.');
    }

    public function destroy(Request $request, Device $device, string $ruleId): RedirectResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        $policy = $device->latestPolicy();
        $rules = collect($policy?->rules ?? [])
            ->reject(fn (array $rule): bool => ($rule['id'] ?? null) === $ruleId)
            ->values()
            ->all();

        $nextVersion = ($policy?->version ?? 0) + 1;

        $device->policies()->create([
            'version' => $nextVersion,
            'rules' => $rules,
        ]);

        $device->forceFill(['last_policy_version' => $nextVersion])->save();

        return back()->with('status', 'Regra removida e politica versionada.');
    }
}
