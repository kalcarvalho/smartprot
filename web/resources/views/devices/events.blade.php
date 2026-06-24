<x-layouts.app title="Eventos | {{ $device->name }} | SmartProt">
    <div class="shell">
        @include('partials.topbar')
        <main>
            <section class="page-title">
                <div>
                    <h1>Eventos</h1>
                    <p>{{ $device->name }} ({{ $device->public_id }})</p>
                </div>
                <a class="button secondary" href="{{ route('devices.show', $device) }}">Voltar ao dispositivo</a>
            </section>

            @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif

            <div class="panel">
                <table>
                    <thead><tr><th>Tipo</th><th>Detalhes</th><th>Ocorrido em</th></tr></thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td><code>{{ $event->type }}</code></td>
                                <td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;">
                                    @if ($event->payload)
                                        <pre style="margin:0;font-size:0.8rem;white-space:pre-wrap;">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $event->occurred_at?->format('d/m/Y H:i:s') ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="muted">Nenhum evento registrado ainda.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="pagination">{{ $events->links() }}</div>
            </div>
        </main>
    </div>
</x-layouts.app>