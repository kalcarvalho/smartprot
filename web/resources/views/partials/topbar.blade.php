<header class="topbar">
    <div class="brand"><span class="brand-mark">S</span> SmartProt</div>
    <nav class="nav">
        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Painel</a>
        <a href="{{ route('devices.index') }}" class="{{ request()->routeIs('devices.*') ? 'active' : '' }}">Smartphones</a>
        <a href="{{ route('app-domain-mappings.index') }}" class="{{ request()->routeIs('app-domain-mappings.*') ? 'active' : '' }}">Mapeamentos</a>
        <a href="{{ route('profile.edit') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}">Meu perfil</a>
    </nav>
    <div class="nav-actions">
        <span>{{ auth()->user()->name }}</span>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="secondary" type="submit">Sair</button>
        </form>
    </div>
</header>
