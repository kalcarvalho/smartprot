<x-layouts.app title="{{ $device->name }} | SmartProt">
    <div class="shell">
        @include('partials.topbar')
        <main>
            <section class="page-title">
                <div>
                    <h1>{{ $device->name }}</h1>
                    <p>{{ $device->public_id }} · Politica v{{ $policy?->version ?? 0 }}</p>
                </div>
                <div style="display:flex; gap:8px;">
                    @if ($device->user_id === null)
                        <form method="post" action="{{ route('devices.claim', $device) }}">
                            @csrf
                            <button class="button" type="submit">Vincular a minha conta</button>
                        </form>
                    @endif
                    <a class="button secondary" href="{{ route('devices.index') }}">Smartphones</a>
                </div>
            </section>

            @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif
            @if (session('device_token'))
                <div class="flash">Token de pareamento gerado. Ele sera mostrado apenas agora.<span class="token">{{ session('device_token') }}</span></div>
            @endif

            <section class="grid stats">
                <div class="stat"><span>Plataforma</span><strong>{{ strtoupper($device->platform) }}</strong></div>
                <div class="stat"><span>Status</span><strong>{{ $device->isOnline() ? 'Online' : 'Offline' }}</strong></div>
                <div class="stat"><span>Bloqueios</span><strong>{{ $settings['protection_enabled'] ? 'Ativos' : 'Pausados' }}</strong></div>
                <div class="stat"><span>VPN</span><strong>{{ $device->vpn_active === null ? '-' : ($device->vpn_active ? 'Ativa' : 'Inativa') }}</strong></div>
            </section>

            <section style="display:flex;gap:8px;margin-bottom:16px;">
                <a class="button secondary" href="{{ route('devices.events', $device) }}">Ver eventos de rede</a>
                <a class="button secondary" href="{{ route('devices.domains.index', $device) }}">Ver dominios observados</a>
            </section>

            <section class="panel control-panel">
                <div>
                    <h2>Controle geral do aparelho</h2>
                    <p class="muted">Pausar envia uma nova politica com a lista de bloqueios vazia para o smartphone.</p>
                </div>
                <form method="post" action="{{ route('devices.protection.update', $device) }}">
                    @csrf
                    @method('patch')
                    <input type="hidden" name="protection_enabled" value="{{ $settings['protection_enabled'] ? 0 : 1 }}">
                    <button class="{{ $settings['protection_enabled'] ? 'danger' : '' }}" type="submit">{{ $settings['protection_enabled'] ? 'Pausar bloqueios' : 'Ativar bloqueios' }}</button>
                </form>
            </section>

            <section class="grid two-col">
                <div class="panel">
                    <h2>Bloqueios e liberacoes</h2>
                    <div class="rule-list">
                        @forelse ($rules as $rule)
                            @php($ruleEnabled = (bool) ($rule['enabled'] ?? true))
                            @php($schedule = $rule['schedule'] ?? null)
                            <div class="rule-item">
                                <div>
                                    <strong>{{ ucfirst($rule['network'] ?? 'blocked') }} · {{ $rule['target'] ?? '-' }}</strong>
                                    <span class="muted">Tipo: {{ $rule['type'] ?? '-' }} · {{ $ruleEnabled ? 'Ativa' : 'Desativada' }}</span>
                                    @if ($schedule)
                                        <span class="muted">Agenda: {{ implode(', ', $schedule['days'] ?? []) ?: 'todos os dias' }} {{ $schedule['starts_at'] ?? '--:--' }}-{{ $schedule['ends_at'] ?? '--:--' }}</span>
                                    @endif
                                    @if (! empty($rule['daily_limit_minutes']))
                                        <span class="muted">Limite diario: {{ $rule['daily_limit_minutes'] }} min</span>
                                    @endif
                                    @if(!empty($rule['notes'])) <span class="muted">{{ $rule['notes'] }}</span> @endif
                                </div>
                                <div class="actions rule-actions">
                                    <form method="post" action="{{ route('devices.rules.update', [$device, $rule['id']]) }}">
                                        @csrf
                                        @method('patch')
                                        <input type="hidden" name="enabled" value="{{ $ruleEnabled ? 0 : 1 }}">
                                        <button class="secondary compact" type="submit">{{ $ruleEnabled ? 'Desativar' : 'Ativar' }}</button>
                                    </form>
                                    <form method="post" action="{{ route('devices.rules.destroy', [$device, $rule['id']]) }}">
                                        @csrf
                                        @method('delete')
                                        <button class="danger compact" type="submit">Remover</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <p class="muted">Nenhuma regra criada. O app cliente ainda nao bloqueia trafego especifico para este aparelho.</p>
                        @endforelse
                    </div>
                </div>

                <form class="panel" method="post" action="{{ route('devices.rules.store', $device) }}">
                    @csrf
                    <h2>Nova regra</h2>

                    <input type="hidden" name="enabled" value="0">
                    <label class="checkbox-line"><input name="enabled" type="checkbox" value="1" checked> Regra ativa</label>

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

                    <input type="hidden" name="schedule_enabled" value="0">
                    <label class="checkbox-line"><input name="schedule_enabled" type="checkbox" value="1"> Usar agenda</label>
                    <div class="weekday-grid">
                        @foreach ([ 'mon' => 'Seg', 'tue' => 'Ter', 'wed' => 'Qua', 'thu' => 'Qui', 'fri' => 'Sex', 'sat' => 'Sab', 'sun' => 'Dom' ] as $value => $label)
                            <label><input type="checkbox" name="schedule_days[]" value="{{ $value }}"> {{ $label }}</label>
                        @endforeach
                    </div>
                    <div class="form-grid grid">
                        <div>
                            <label for="starts_at">Inicio</label>
                            <input id="starts_at" name="starts_at" type="time" value="{{ old('starts_at') }}">
                        </div>
                        <div>
                            <label for="ends_at">Fim</label>
                            <input id="ends_at" name="ends_at" type="time" value="{{ old('ends_at') }}">
                        </div>
                    </div>
                    @error('starts_at')<div class="error">{{ $message }}</div>@enderror
                    @error('ends_at')<div class="error">{{ $message }}</div>@enderror

                    <label for="daily_limit_minutes">Limite diario de uso</label>
                    <input id="daily_limit_minutes" name="daily_limit_minutes" type="number" min="1" max="1440" value="{{ old('daily_limit_minutes') }}" placeholder="Ex.: 60">
                    @error('daily_limit_minutes')<div class="error">{{ $message }}</div>@enderror

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