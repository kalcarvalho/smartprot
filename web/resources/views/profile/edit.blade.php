<x-layouts.app title="Meu perfil | SmartProt">
    <div class="shell">
        @include('partials.topbar')
        <main>
            <section class="page-title">
                <div>
                    <h1>Meu perfil</h1>
                    <p>Atualize os dados do responsavel e a senha de acesso ao painel.</p>
                </div>
            </section>

            @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif

            <form class="panel" method="post" action="{{ route('profile.update') }}">
                @csrf
                @method('put')

                <div class="grid form-grid">
                    <div>
                        <label for="name">Nome</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required>
                        @error('name')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="email">E-mail</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
                        @error('email')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="grid form-grid">
                    <div>
                        <label for="current_password">Senha atual</label>
                        <input id="current_password" name="current_password" type="password" autocomplete="current-password">
                        @error('current_password')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="password">Nova senha</label>
                        <input id="password" name="password" type="password" autocomplete="new-password">
                        @error('password')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>

                <label for="password_confirmation">Confirmar nova senha</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">

                <div class="actions" style="margin-top: 20px;">
                    <button type="submit">Salvar perfil</button>
                </div>
            </form>
        </main>
    </div>
</x-layouts.app>
