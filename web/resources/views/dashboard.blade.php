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

            @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif

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
                        <thead><tr><th>Nome</th><th>Status</th><th>Bloqueio</th><th>Politica</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($recentDevices as $device)
                                @php($online = $device->isOnline())
                                @php($latestPolicy = $device->latestPolicy())
                                @php($protectionEnabled = (bool) ($latestPolicy?->settings['protection_enabled'] ?? true))
                                <tr>
                                    <td><a href="{{ route('devices.show', $device) }}"><strong>{{ $device->name }}</strong></a><br><span class="muted">{{ $device->public_id }}</span></td>
                                    <td><span class="status {{ $online ? '' : 'offline' }}">{{ $online ? 'Online' : 'Offline' }}</span></td>
                                    <td><span class="status {{ $protectionEnabled ? '' : 'offline' }}">{{ $protectionEnabled ? 'Ativo' : 'Pausado' }}</span></td>
                                    <td>{{ $latestPolicy?->version ?? '-' }}</td>
                                    <td>
                                        <form method="post" action="{{ route('devices.protection.update', $device) }}">
                                            @csrf
                                            @method('patch')
                                            <input type="hidden" name="protection_enabled" value="{{ $protectionEnabled ? 0 : 1 }}">
                                            <button class="secondary compact" type="submit">{{ $protectionEnabled ? 'Pausar' : 'Ativar' }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="muted">Nenhum smartphone registrado ainda.</td></tr>
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