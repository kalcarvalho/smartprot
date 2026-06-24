<x-layouts.app title="{{ $device->name }} | SmartProt">
    <div class="shell">
        @include('partials.topbar')
        <main>
            <section class="page-title">
                <div>
                    <h1>{{ $device->name }}</h1>
                    <p>{{ $device->public_id }} · Politica v{{ $policy?->version ?? 0 }}</p>
                </div>
                <a class="button secondary" href="{{ route('devices.index') }}">Smartphones</a>
            </section>

            @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif
            @if (session('device_token'))
                <div class="flash">Token de pareamento gerado. Ele sera mostrado apenas agora.<span class="token">{{ session('device_token') }}</span></div>
            @endif

            <section class="grid stats">
                <div class="stat"><span>Plataforma</span><strong>{{ strtoupper($device->platform) }}</strong></div>
                <div class="stat"><span>Status</span><strong>{{ $device->last_seen_at && $device->last_seen_at->gte(now()->subMinutes(5)) ? 'Online' : 'Offline' }}</strong></div>
                <div class="stat"><span>Bateria</span><strong>{{ $device->battery_percent ?? '-' }}{{ $device->battery_percent !== null ? '%' : '' }}</strong></div>
                <div class="stat"><span>VPN</span><strong>{{ $device->vpn_active === null ? '-' : ($device->vpn_active ? 'Ativa' : 'Inativa') }}</strong></div>
            </section>

            <section class="grid two-col">
                <div class="panel">
                    <h2>Bloqueios e liberacoes</h2>
                    <div class="rule-list">
                        @forelse ($rules as $rule)
                            <div class="rule-item">
                                <div>
                                    <strong>{{ ucfirst($rule['network'] ?? 'blocked') }} · {{ $rule['target'] ?? '-' }}</strong>
                                    <span class="muted">Tipo: {{ $rule['type'] ?? '-' }} @if(!empty($rule['notes'])) · {{ $rule['notes'] }} @endif</span>
                                </div>
                                <form method="post" action="{{ route('devices.rules.destroy', [$device, $rule['id']]) }}">
                                    @csrf
                                    @method('delete')
                                    <button class="danger" type="submit">Remover</button>
                                </form>
                            </div>
                        @empty
                            <p class="muted">Nenhuma regra criada. O app cliente ainda nao bloqueia trafego especifico para este aparelho.</p>
                        @endforelse
                    </div>
                </div>

                <form class="panel" method="post" action="{{ route('devices.rules.store', $device) }}">
                    @csrf
                    <h2>Nova regra</h2>

                    <label for="type">Tipo</label>
                    <select id="type" name="type" required>
                        <option value="app">Aplicativo Android</option>
                        <option value="domain">Dominio</option>
                        <option value="url">URL</option>
                        <option value="ip">IP ou rede</option>
                    </select>
                    @error('type')<div class="error">{{ $message }}</div>@enderror

                    <label for="target">Alvo</label>
                    <input id="target" name="target" type="text" value="{{ old('target') }}" placeholder="com.instagram.android ou tiktok.com" required>
                    @error('target')<div class="error">{{ $message }}</div>@enderror

                    <label for="network">Acao</label>
                    <select id="network" name="network" required>
                        <option value="blocked">Bloquear internet</option>
                        <option value="allowed">Liberar internet</option>
                    </select>
                    @error('network')<div class="error">{{ $message }}</div>@enderror

                    <label for="notes">Observacoes</label>
                    <textarea id="notes" name="notes" placeholder="Opcional">{{ old('notes') }}</textarea>
                    @error('notes')<div class="error">{{ $message }}</div>@enderror

                    <div class="actions" style="margin-top: 20px;">
                        <button type="submit">Adicionar regra</button>
                    </div>
                </form>
            </section>
        </main>
    </div>
</x-layouts.app>
