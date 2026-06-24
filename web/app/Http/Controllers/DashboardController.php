<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\Policy;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $userId = $request->user()->id;
        $deviceIds = Device::where('user_id', $userId)->pluck('id');

        return view('dashboard', [
            'deviceCount' => $deviceIds->count(),
            'onlineDeviceCount' => Device::whereIn('id', $deviceIds)
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->count(),
            'policyCount' => Policy::whereIn('device_id', $deviceIds)->count(),
            'eventCount' => DeviceEvent::whereIn('device_id', $deviceIds)->count(),
            'recentDevices' => Device::whereIn('id', $deviceIds)->latest('updated_at')->limit(8)->get(),
            'recentEvents' => DeviceEvent::with('device')
                ->whereIn('device_id', $deviceIds)
                ->latest('occurred_at')
                ->limit(8)
                ->get(),
        ]);
    }
}
