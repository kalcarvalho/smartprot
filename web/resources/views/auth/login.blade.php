<x-layouts.app title="Entrar | SmartProt">
    <div class="auth-page">
        <section class="auth-visual">
            <div class="brand"><span class="brand-mark">S</span> SmartProt</div>
            <div>
                <h1>Controle de internet por aplicativo, domínio e horário.</h1>
                <p>Painel dos responsáveis para acompanhar dispositivos, políticas ativas e eventos enviados pelo celular cliente.</p>
            </div>
        </section>

        <section class="auth-panel">
            <form class="login-box" method="post" action="{{ route('login.store') }}">
                @csrf
                <h2>Acessar painel</h2>
                <p class="muted">Entre com o usuário responsável para gerenciar os dispositivos.</p>

                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                @error('email')<div class="error">{{ $message }}</div>@enderror

                <label for="password">Senha</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                @error('password')<div class="error">{{ $message }}</div>@enderror

                <label class="remember">
                    <input type="checkbox" name="remember" value="1">
                    Manter conectado
                </label>

                <button type="submit">Entrar</button>
            </form>
        </section>
    </div>
</x-layouts.app>
