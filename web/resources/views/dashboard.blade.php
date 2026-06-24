<x-layouts.app title="Painel | SmartProt">
    <div class="shell">
        @include('partials.topbar')

        <main>
            <section class="page-title">
                <div>
                    <h1>Painel de controle</h1>
                    <p>Visao inicial dos smartphones cadastrados, politicas distribuidas e eventos recebidos da API cliente.</p>
                </div>
                <a class="button" href="{{ route('devices.create') }}">Registrar smartphone</a>
            </section>

            <section class="grid stats">
                <div class="stat"><span>Smartphones</span><strong>{{ $deviceCount }}</strong></div>
                <div class="stat"><span>Online agora</span><strong>{{ $onlineDeviceCount }}</strong></div>
                <div class="stat"><span>Politicas</span><strong>{{ $policyCount }}</strong></div>
                <div class="stat"><span>Eventos</span><strong>{{ $eventCount }}</strong></div>
            </section>

            <section class="grid two-col">
                <div class="panel">
                    <h2>Smartphones recentes</h2>
                    <table>
                        <thead><tr><th>Nome</th><th>Plataforma</th><th>Status</th><th>Politica</th></tr></thead>
                        <tbody>
                            @forelse ($recentDevices as $device)
                                @php($online = $device->last_seen_at && $device->last_seen_at->gte(now()->subMinutes(5)))
                                <tr>
                                    <td><a href="{{ route('devices.show', $device) }}"><strong>{{ $device->name }}</strong></a><br><span class="muted">{{ $device->public_id }}</span></td>
                                    <td>{{ $device->platform }}</td>
                                    <td><span class="status {{ $online ? '' : 'offline' }}">{{ $online ? 'Online' : 'Offline' }}</span></td>
                                    <td>{{ $device->last_policy_version ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="muted">Nenhum smartphone registrado ainda.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="panel">
                    <h2>Eventos recentes</h2>
                    <table>
                        <thead><tr><th>Tipo</th><th>Smartphone</th><th>Quando</th></tr></thead>
                        <tbody>
                            @forelse ($recentEvents as $event)
                                <tr>
                                    <td>{{ $event->type }}</td>
                                    <td>{{ $event->device?->name ?? '-' }}</td>
                                    <td>{{ $event->occurred_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="muted">Nenhum evento recebido ainda.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</x-layouts.app>
