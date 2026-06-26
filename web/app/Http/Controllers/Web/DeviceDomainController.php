<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AppDomainMapping;
use App\Models\Device;
use App\Models\DeviceDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeviceDomainController extends Controller
{
    public function index(Request $request, Device $device): View
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $domains = $device->domains()
            ->orderByDesc('last_seen')
            ->paginate(50);

        $knownApps = AppDomainMapping::query()
            ->select('app_package')
            ->distinct()
            ->orderBy('app_package')
            ->pluck('app_package');

        return view('devices.domains', [
            'device' => $device,
            'domains' => $domains,
            'knownApps' => $knownApps,
        ]);
    }

    public function apps(Request $request, Device $device): View
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);

        $rulesByApp = collect($device->latestPolicy()?->rules ?? [])
            ->filter(fn (array $rule): bool => ($rule['type'] ?? null) === 'app')
            ->keyBy('target');

        $apps = $device->domains()
            ->whereNotNull('app_package')
            ->get()
            ->groupBy('app_package')
            ->map(function ($domains, $appPackage) use ($rulesByApp) {
                $rule = $rulesByApp->get($appPackage);

                return [
                    'app_package' => $appPackage,
                    'domain_count' => $domains->count(),
                    'last_seen' => $domains->max('last_seen'),
                    'blocked' => $rule !== null && ($rule['network'] ?? null) === 'blocked' && ($rule['enabled'] ?? true),
                ];
            })
            ->sortByDesc('last_seen')
            ->values();

        return view('devices.apps', [
            'device' => $device,
            'apps' => $apps,
        ]);
    }

    public function associate(Request $request, Device $device, DeviceDomain $domain): RedirectResponse
    {
        abort_unless($device->user_id === null || $device->user_id === $request->user()->id, 404);
        abort_unless($domain->device_id === $device->id, 404);

        $data = $request->validate([
            'app_package' => ['required', 'string', 'max:190'],
        ]);

        $appPackage = trim($data['app_package']);

        $domain->forceFill(['app_package' => $appPackage])->save();

        // Upserting here is what makes the mapping dynamic: the next device that
        // syncs its policy will get this domain back in app_domains, with no
        // app update or hardcoded list required.
        AppDomainMapping::firstOrCreate(
            ['app_package' => $appPackage, 'domain' => $domain->domain],
            ['user_id' => $request->user()->id]
        );

        return back()->with('status', "Dominio {$domain->domain} associado a {$appPackage}.");
    }
}
