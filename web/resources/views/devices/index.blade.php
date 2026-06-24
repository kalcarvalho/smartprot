<x-layouts.app title="Smartphones | SmartProt">
    <div class="shell">
        @include('partials.topbar')
        <main>
            <section class="page-title">
                <div>
                    <h1>Smartphones</h1>
                    <p>Cadastre celulares cliente e acompanhe a politica ativa de cada aparelho.</p>
                </div>
                <a class="button" href="{{ route('devices.create') }}">Registrar smartphone</a>
            </section>

            @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif

            <div class="panel">
                <table>
                    <thead><tr><th>Smartphone</th><th>Plataforma</th><th>Status</th><th>Politica</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($devices as $device)
                            @php($online = $device->last_seen_at && $device->last_seen_at->gte(now()->subMinutes(5)))
                            <tr>
                                <td><strong>{{ $device->name }}</strong><br><span class="muted">{{ $device->public_id }}</span></td>
                                <td>{{ $device->platform }}</td>
                                <td><span class="status {{ $online ? '' : 'offline' }}">{{ $online ? 'Online' : 'Offline' }}</span></td>
                                <td>{{ $device->policies_max_version ?? '-' }}</td>
                                <td><a class="button secondary" href="{{ route('devices.show', $device) }}">Abrir</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">Nenhum smartphone registrado ainda.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="pagination">{{ $devices->links() }}</div>
            </div>
        </main>
    </div>
</x-layouts.app>
