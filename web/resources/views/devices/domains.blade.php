<x-layouts.app title="Dominios | {{ $device->name }} | SmartProt">
    <div class="shell">
        @include('partials.topbar')
        <main>
            <section class="page-title">
                <div>
                    <h1>Dominios observados</h1>
                    <p>{{ $device->name }} ({{ $device->public_id }})</p>
                </div>
                <a class="button secondary" href="{{ route('devices.show', $device) }}">Voltar ao dispositivo</a>
            </section>

            @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif

            <datalist id="known-apps">
                @foreach ($knownApps as $app)
                    <option value="{{ $app }}">
                @endforeach
            </datalist>

            <div class="panel">
                <p class="muted" style="margin-top:0;">Dominios que o smartphone tentou acessar, reportados pela VPN. Associe a um aplicativo para que o bloqueio por app passe a cobrir esse dominio em todos os aparelhos, ou bloqueie diretamente.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Dominio</th>
                            <th>Aplicativo</th>
                            <th>Visto</th>
                            <th>Ultima vez</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($domains as $domain)
                            <tr>
                                <td><code>{{ $domain->domain }}</code></td>
                                <td>{{ $domain->app_package ?? '—' }}</td>
                                <td>{{ $domain->seen_count }}x</td>
                                <td>{{ $domain->last_seen?->format('d/m/Y H:i') ?? '-' }}</td>
                                <td>
                                    <div class="actions" style="flex-wrap:wrap;">
                                        <form method="post" action="{{ route('devices.domains.associate', [$device, $domain]) }}" style="display:flex;gap:6px;">
                                            @csrf
                                            @method('patch')
                                            <input type="text" name="app_package" list="known-apps" placeholder="com.exemplo.app" value="{{ $domain->app_package }}" style="min-height:34px;width:200px;" required>
                                            <button class="secondary compact" type="submit">Associar</button>
                                        </form>
                                        <form method="post" action="{{ route('devices.rules.store', $device) }}">
                                            @csrf
                                            <input type="hidden" name="type" value="domain">
                                            <input type="hidden" name="network" value="blocked">
                                            <input type="hidden" name="target" value="{{ $domain->domain }}">
                                            <button class="danger compact" type="submit">Bloquear</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">Nenhum dominio observado ainda. O smartphone reporta dominios periodicamente enquanto a VPN esta ativa.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="pagination">{{ $domains->links() }}</div>
            </div>
        </main>
    </div>
</x-layouts.app>
