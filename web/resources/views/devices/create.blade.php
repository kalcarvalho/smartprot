<x-layouts.app title="Registrar smartphone | SmartProt">
    <div class="shell">
        @include('partials.topbar')
        <main>
            <section class="page-title">
                <div>
                    <h1>Registrar smartphone</h1>
                    <p>Crie um registro para parear o app cliente Android com esta conta.</p>
                </div>
                <a class="button secondary" href="{{ route('devices.index') }}">Voltar</a>
            </section>

            <form class="panel" method="post" action="{{ route('devices.store') }}">
                @csrf
                <label for="name">Nome do aparelho</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="Celular do filho" required>
                @error('name')<div class="error">{{ $message }}</div>@enderror

                <label for="platform">Plataforma</label>
                <select id="platform" name="platform" required>
                    <option value="android" @selected(old('platform', 'android') === 'android')>Android</option>
                </select>
                @error('platform')<div class="error">{{ $message }}</div>@enderror

                <label for="device_fingerprint">Identificador do aparelho</label>
                <input id="device_fingerprint" name="device_fingerprint" type="text" value="{{ old('device_fingerprint') }}" placeholder="Opcional nesta fase">
                @error('device_fingerprint')<div class="error">{{ $message }}</div>@enderror

                <div class="actions" style="margin-top: 20px;">
                    <button type="submit">Registrar</button>
                </div>
            </form>
        </main>
    </div>
</x-layouts.app>
