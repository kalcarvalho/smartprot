<x-layouts.app title="Apps | {{ $device->name }} | SmartProt">
    <div class="shell">
        @include('partials.topbar')
        <main>
            <section class="page-title">
                <div>
                    <h1>Apps observados</h1>
                    <p>{{ $device->name }} ({{ $device->public_id }})</p>
                </div>
                <a class="button secondary" href="{{ route('devices.show', $device) }}">Voltar ao dispositivo</a>
            </section>

            @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif

            <div class="panel">
                <p class="muted" style="margin-top:0;">
                    Todo app que tenta acessar a internet aparece aqui automaticamente, liberado por padrao (igual a um firewall sem root) -- bloqueie o que quiser individualmente.
                </p>
                <table>
                    <thead>
                        <tr>
                            <th>App</th>
                            <th>Dominios vistos</th>
                            <th>Ultima vez</th>
                            <th>Status</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($apps as $app)
                            <tr>
                                <td><code>{{ $app['app_package'] }}</code></td>
                                <td>{{ $app['domain_count'] }}</td>
                                <td>{{ $app['last_seen']?->format('d/m/Y H:i') ?? '-' }}</td>
                                <td><span class="status {{ $app['blocked'] ? 'offline' : '' }}">{{ $app['blocked'] ? 'Bloqueado' : 'Liberado' }}</span></td>
                                <td>
                                    <form method="post" action="{{ route('devices.apps.toggle-block', [$device, $app['app_package']]) }}">
                                        @csrf
                                        @method('patch')
                                        <input type="hidden" name="blocked" value="{{ $app['blocked'] ? 0 : 1 }}">
                                        <button class="{{ $app['blocked'] ? 'secondary' : 'danger' }} compact" type="submit">
                                            {{ $app['blocked'] ? 'Liberar' : 'Bloquear' }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">Nenhum app observado ainda. Eles aparecem aqui conforme tentam acessar a internet com a VPN ativa.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</x-layouts.app>
