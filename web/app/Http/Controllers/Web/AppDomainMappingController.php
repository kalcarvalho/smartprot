<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AppDomainMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppDomainMappingController extends Controller
{
    public function index(): View
    {
        $mappings = AppDomainMapping::query()
            ->orderBy('app_package')
            ->orderBy('domain')
            ->get()
            ->groupBy('app_package');

        return view('app-domain-mappings.index', [
            'mappings' => $mappings,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_package' => ['required', 'string', 'max:190'],
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $appPackage = trim($data['app_package']);
        $domain = strtolower(trim($data['domain']));

        AppDomainMapping::firstOrCreate(
            ['app_package' => $appPackage, 'domain' => $domain],
            ['user_id' => $request->user()->id]
        );

        return back()->with('status', 'Mapeamento adicionado.');
    }

    public function destroy(AppDomainMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return back()->with('status', 'Mapeamento removido.');
    }
}
