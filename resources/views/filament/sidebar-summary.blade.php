@php
    use App\Enums\CabinetStatus;
    use App\Models\Cabinet;
    use App\Models\HostedLicenseGrant;
    use Illuminate\Support\Facades\Cache;

    // Rendered on every panel page, so the two counts are cached briefly
    // rather than queried per navigation.
    $summary = auth()->user()?->is_platform_admin === true
        ? Cache::remember('platform.sidebar-summary', now()->addSeconds(30), fn (): array => [
            'pending' => Cabinet::query()->where('status', CabinetStatus::PENDING->value)->count(),
            'keys' => HostedLicenseGrant::withoutCabinetScope()->outstanding()->count(),
        ])
        : null;
@endphp

@if ($summary !== null)
    <div class="drz-console-card">
        <span class="drz-console-card__title">Console plateforme</span>

        <span class="drz-console-card__row">
            <span class="drz-console-card__muted">Cabinets en attente</span>
            <span class="drz-console-card__value">{{ $summary['pending'] }}</span>
        </span>

        <span class="drz-console-card__row">
            <span class="drz-console-card__muted">Clés non utilisées</span>
            <span class="drz-console-card__value">{{ $summary['keys'] }}</span>
        </span>

        <span class="drz-console-card__row">
            <span class="drz-console-card__muted">Version</span>
            <span class="drz-console-card__value">{{ config('medismart.version', '—') }}</span>
        </span>
    </div>
@endif
