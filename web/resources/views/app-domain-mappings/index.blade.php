<x-layouts.app title="Mapeamentos de app | SmartProt">
    <div class="shell">
        @include('partials.topbar')
        <main>
            <section class="page-title">
                <div>
                    <h1>Mapeamentos de aplicativo</h1>
                    <p>Dominios conhecidos de cada aplicativo, usados para expandir regras de bloqueio por app em todos os smartphones.</p>
                </div>
            </section>

            @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif

            <section class="grid two-col">
                <div class="panel">
                    <h2>Mapeamentos</h2>
                    @forelse ($mappings as $appPackage => $rows)
                        <div class="rule-item" style="grid-template-columns: 1fr;">
                            <div>
                                <strong>{{ $appPackage }}</strong>
                                <div class="actions" style="margin-top:8px;flex-wrap:wrap;">
                                    @foreach ($rows as $row)
                                        <form method="post" action="{{ route('app-domain-mappings.destroy', $row) }}">
                                            @csrf
                                            @method('delete')
                                            <button class="secondary compact" type="submit" title="Remover">{{ $row->domain }} &times;</button>
                                        </form>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="muted">Nenhum mapeamento cadastrado ainda.</p>
                    @endforelse
                </div>

                <form class="panel" method="post" action="{{ route('app-domain-mappings.store') }}">
                    @csrf
                    <h2>Novo mapeamento</h2>

                    <label for="app_package">Pacote do aplicativo</label>
                    <input id="app_package" name="app_package" type="text" value="{{ old('app_package') }}" placeholder="org.telegram.messenger" required>
                    @error('app_package')<div class="error">{{ $message }}</div>@enderror

                    <label for="domain">Dominio</label>
                    <input id="domain" name="domain" type="text" value="{{ old('domain') }}" placeholder="t.me" required>
                    @error('domain')<div class="error">{{ $message }}</div>@enderror

                    <div class="actions" style="margin-top:20px;">
                        <button type="submit">Adicionar mapeamento</button>
                    </div>
                </form>
            </section>
        </main>
    </div>
</x-layouts.app>
