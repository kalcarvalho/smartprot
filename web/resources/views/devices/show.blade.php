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
                <div class="stat"><span>Icone do app</span><strong>{{ ($settings['app_icon_visible'] ?? true) ? 'Visivel' : 'Oculto' }}</strong></div>
            </section>

            <section class="panel" style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:1.25rem;">
                <span class="muted">Proximo sync de politica esperado</span>
                <strong id="policy-sync-countdown" style="font-size:32px;font-family:monospace;"
                    data-last-sync="{{ $lastPolicySync?->toISOString() }}"
                    data-interval-minutes="{{ $policySyncIntervalMinutes }}"
                    data-server-now="{{ now()->toISOString() }}">--:--</strong>
                <span id="policy-sync-state" class="muted">
                    {{ $lastPolicySync ? 'Ultimo sync aplicado '.$lastPolicySync->diffForHumans() : 'Nenhum sync de politica registrado ainda' }}
                </span>
                <span class="muted" style="font-size:12px;text-align:center;max-width:480px;">
                    Estimativa baseada no intervalo configurado no app ({{ $policySyncIntervalMinutes }} min). O Android pode atrasar a execucao real (bateria, rede, Doze).
                </span>
            </section>

            <section style="display:flex;gap:8px;">
                <a class="button secondary" href="{{ route('devices.events', $device) }}">Ver eventos de rede</a>
                <a class="button secondary" href="{{ route('devices.domains.index', $device) }}">Ver dominios observados</a>
            </section>

            <script>
                (function () {
                    var el = document.getElementById('policy-sync-countdown');
                    var stateEl = document.getElementById('policy-sync-state');
                    if (!el) return;

                    var intervalMinutes = parseInt(el.dataset.intervalMinutes, 10) || 5;
                    var lastSync = el.dataset.lastSync ? new Date(el.dataset.lastSync) : null;
                    var serverNow = new Date(el.dataset.serverNow);
                    var clockOffsetMs = Date.now() - serverNow.getTime();

                    if (!lastSync) {
                        el.textContent = '--:--';
                        return;
                    }

                    var nextSyncAt = new Date(lastSync.getTime() + intervalMinutes * 60000);

                    function render() {
                        var nowAdjusted = new Date(Date.now() - clockOffsetMs);
                        var remainingMs = nextSyncAt.getTime() - nowAdjusted.getTime();

                        if (remainingMs <= 0) {
                            el.textContent = '00:00';
                            stateEl.textContent = 'Janela de sync atingida, aguardando o Android executar o job';
                            return;
                        }

                        var totalSeconds = Math.floor(remainingMs / 1000);
                        var minutes = Math.floor(totalSeconds / 60);
                        var seconds = totalSeconds % 60;
                        el.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
                    }

                    render();
                    setInterval(render, 1000);
                })();
            </script>

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
                @php($iconVisible = $settings['app_icon_visible'] ?? true)
                <form method="post" action="{{ route('devices.icon-visibility.update', $device) }}">
                    @csrf
                    @method('patch')
                    <input type="hidden" name="app_icon_visible" value="{{ $iconVisible ? 0 : 1 }}">
                    <button class="secondary" type="submit">{{ $iconVisible ? 'Ocultar icone do app' : 'Reativar icone do app' }}</button>
                </form>
            </section>

            @php($defaultNetwork = $settings['default_network'] ?? 'allowed')
            <section class="panel">
                <h2>Politica padrao (firewall)</h2>
                <p class="muted">Define o que acontece quando nenhuma regra abaixo se aplica. Regras individuais sempre tem prioridade sobre a politica padrao.</p>
                <div style="display:flex;gap:8px;margin-top:8px;">
                    <form method="post" action="{{ route('devices.default-network.update', $device) }}">
                        @csrf
                        @method('patch')
                        <input type="hidden" name="default_network" value="allowed">
                        <button class="{{ $defaultNetwork === 'allowed' ? '' : 'secondary' }}" type="submit" {{ $defaultNetwork === 'allowed' ? 'disabled' : '' }}>
                            Liberar tudo{{ $defaultNetwork === 'allowed' ? ' (ativo)' : '' }}
                        </button>
                    </form>
                    <form method="post" action="{{ route('devices.default-network.update', $device) }}">
                        @csrf
                        @method('patch')
                        <input type="hidden" name="default_network" value="blocked">
                        <button class="{{ $defaultNetwork === 'blocked' ? 'danger' : 'secondary' }}" type="submit" {{ $defaultNetwork === 'blocked' ? 'disabled' : '' }}>
                            Bloquear tudo{{ $defaultNetwork === 'blocked' ? ' (ativo)' : '' }}
                        </button>
                    </form>
                </div>
            </section>

            <section class="panel">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h2>Bloqueios e liberacoes</h2>
                    <button type="button" onclick="document.getElementById('rule-modal').showModal(); window.resetRuleModal();">+ Nova regra</button>
                </div>
                <p class="muted" style="margin-top:-4px;">
                    Avaliadas de cima para baixo como num firewall: a primeira regra que combinar com o trafego decide; se nenhuma combinar, vale a politica padrao acima ({{ $defaultNetwork === 'blocked' ? 'bloquear tudo' : 'liberar tudo' }}).
                </p>
                <div class="rule-list">
                    @forelse ($rules as $rule)
                        @php($ruleEnabled = (bool) ($rule['enabled'] ?? true))
                        @php($schedule = $rule['schedule'] ?? null)
                        @php($editPayload = [
                            'id' => $rule['id'],
                            'type' => $rule['type'] ?? 'app',
                            'target' => $rule['target'] ?? '',
                            'network' => $rule['network'] ?? 'blocked',
                            'enabled' => $ruleEnabled,
                            'schedule_days' => $schedule['days'] ?? [],
                            'starts_at' => $schedule['starts_at'] ?? '',
                            'ends_at' => $schedule['ends_at'] ?? '',
                            'daily_limit_minutes' => $rule['daily_limit_minutes'] ?? '',
                            'notes' => $rule['notes'] ?? '',
                        ])
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
                                <button type="button" class="secondary compact"
                                    onclick='window.openRuleModalForEdit(@json($editPayload)); document.getElementById("rule-modal").showModal();'>Editar</button>
                                <form method="post" action="{{ route('devices.rules.update', [$device, $rule['id']]) }}">
                                    @csrf
                                    @method('patch')
                                    <input type="hidden" name="enabled" value="{{ $ruleEnabled ? 0 : 1 }}">
                                    <button class="secondary compact" type="submit">{{ $ruleEnabled ? 'Desativar' : 'Ativar' }}</button>
                                </form>
                                <form method="post" action="{{ route('devices.rules.duplicate', [$device, $rule['id']]) }}">
                                    @csrf
                                    <button class="secondary compact" type="submit">Duplicar</button>
                                </form>
                                @if ($otherDevices->isNotEmpty())
                                    <form method="post" action="{{ route('devices.rules.copy-to', [$device, $rule['id']]) }}" style="display:flex;gap:4px;align-items:center;">
                                        @csrf
                                        <select name="target_device_id" required style="height:32px;font-size:13px;">
                                            <option value="" disabled selected>Copiar para...</option>
                                            @foreach ($otherDevices as $other)
                                                <option value="{{ $other->id }}">{{ $other->name }}</option>
                                            @endforeach
                                        </select>
                                        <button class="secondary compact" type="submit">Copiar</button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('devices.rules.destroy', [$device, $rule['id']]) }}">
                                    @csrf
                                    @method('delete')
                                    <button class="danger compact" type="submit">Remover</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="muted">Nenhuma regra criada ainda. Use "+ Nova regra" para adicionar uma exececao a politica padrao.</p>
                    @endforelse
                </div>
            </section>

            <dialog id="rule-modal" style="border:none;border-radius:var(--border-radius-lg, 12px);padding:0;max-width:520px;width:100%;">
                <form id="rule-form" class="panel" method="post" action="{{ route('devices.rules.store', $device) }}" style="margin:0;">
                    @csrf
                    <input type="hidden" id="rule-method-field" name="_method" value="POST">
                    <h2 id="rule-modal-title">Nova regra</h2>

                    <input type="hidden" name="enabled" value="0">
                    <label class="checkbox-line"><input id="rule-enabled" name="enabled" type="checkbox" value="1" checked> Regra ativa</label>

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

                    <input type="hidden" name="schedule_enabled" id="schedule_enabled" value="0">
                    <label class="checkbox-line"><input id="schedule_enabled_checkbox" type="checkbox" value="1" onchange="document.getElementById('schedule_enabled').value = this.checked ? 1 : 0;"> Usar agenda</label>
                    <div class="weekday-grid">
                        @foreach ([ 'mon' => 'Seg', 'tue' => 'Ter', 'wed' => 'Qua', 'thu' => 'Qui', 'fri' => 'Sex', 'sat' => 'Sab', 'sun' => 'Dom' ] as $value => $label)
                            <label><input type="checkbox" class="schedule-day" name="schedule_days[]" value="{{ $value }}"> {{ $label }}</label>
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

                    <div class="actions" style="margin-top: 20px;display:flex;justify-content:flex-end;gap:8px;">
                        <button type="button" class="secondary" onclick="document.getElementById('rule-modal').close();">Cancelar</button>
                        <button id="rule-submit-button" type="submit">Adicionar regra</button>
                    </div>
                </form>
            </dialog>

            <script>
                (function () {
                    var form = document.getElementById('rule-form');
                    var storeUrl = '{{ route('devices.rules.store', $device) }}';
                    var editUrlTemplate = '{{ route('devices.rules.edit', [$device, '__ID__']) }}';

                    window.resetRuleModal = function () {
                        form.action = storeUrl;
                        document.getElementById('rule-method-field').value = 'POST';
                        document.getElementById('rule-modal-title').textContent = 'Nova regra';
                        document.getElementById('rule-submit-button').textContent = 'Adicionar regra';
                        form.reset();
                        document.getElementById('schedule_enabled').value = '0';
                    };

                    window.openRuleModalForEdit = function (rule) {
                        form.action = editUrlTemplate.replace('__ID__', rule.id);
                        document.getElementById('rule-method-field').value = 'PUT';
                        document.getElementById('rule-modal-title').textContent = 'Editar regra';
                        document.getElementById('rule-submit-button').textContent = 'Salvar alteracoes';

                        document.getElementById('rule-enabled').checked = !!rule.enabled;
                        document.getElementById('type').value = rule.type;
                        document.getElementById('target').value = rule.target;
                        document.getElementById('network').value = rule.network;
                        document.getElementById('daily_limit_minutes').value = rule.daily_limit_minutes || '';
                        document.getElementById('notes').value = rule.notes || '';

                        var hasSchedule = rule.starts_at || rule.ends_at || (rule.schedule_days && rule.schedule_days.length);
                        document.getElementById('schedule_enabled_checkbox').checked = hasSchedule;
                        document.getElementById('schedule_enabled').value = hasSchedule ? '1' : '0';
                        document.getElementById('starts_at').value = rule.starts_at || '';
                        document.getElementById('ends_at').value = rule.ends_at || '';
                        document.querySelectorAll('.schedule-day').forEach(function (cb) {
                            cb.checked = (rule.schedule_days || []).indexOf(cb.value) !== -1;
                        });
                    };
                })();
            </script>
        </main>
    </div>
</x-layouts.app>