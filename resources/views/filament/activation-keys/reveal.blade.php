@use('App\Support\ClipboardJs')

{{-- Layout is inlined rather than using utility classes: the panel ships its
     own compiled stylesheet, so arbitrary Tailwind utilities are not
     guaranteed to be present in it. The admin CSP allows inline styles. --}}
<style>
    .drz-key-reveal {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .drz-key-reveal__code {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        padding: 1rem 1.25rem;
        border-radius: 0.75rem;
        border: 1px dashed color-mix(in srgb, currentColor 35%, transparent);
        background: color-mix(in srgb, currentColor 6%, transparent);
    }

    .drz-key-reveal__value {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 1.05rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        word-break: break-all;
    }

    .drz-key-reveal__button {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.4rem 0.85rem;
        border-radius: 0.5rem;
        border: 1px solid color-mix(in srgb, currentColor 25%, transparent);
        background: color-mix(in srgb, currentColor 8%, transparent);
        font-size: 0.8125rem;
        font-weight: 600;
        cursor: pointer;
    }

    .drz-key-reveal__meta {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
        gap: 0.75rem 1.5rem;
        font-size: 0.8125rem;
    }

    .drz-key-reveal__label {
        opacity: 0.65;
        display: block;
        margin-bottom: 0.125rem;
    }

    .drz-key-reveal__note {
        font-size: 0.8125rem;
        opacity: 0.75;
        line-height: 1.5;
    }
</style>

<div class="drz-key-reveal">
    @if ($code === null)
        <p class="drz-key-reveal__note">
            Cette clé a été émise avant l’enregistrement récupérable des codes : seule son empreinte est conservée.
            Révoquez-la et générez-en une nouvelle pour {{ $grant->cabinet?->name ?? 'ce cabinet' }}.
        </p>
    @else
        <div class="drz-key-reveal__code" x-data="{ copied: false }">
            <span class="drz-key-reveal__value">{{ $code }}</span>

            <button
                type="button"
                class="drz-key-reveal__button"
                x-on:click="{!! ClipboardJs::copy($code) !!}; copied = true; setTimeout(() => copied = false, 2000)"
            >
                <span x-text="copied ? 'Copié !' : 'Copier la clé'">Copier la clé</span>
            </button>
        </div>
    @endif

    <div class="drz-key-reveal__meta">
        <div>
            <span class="drz-key-reveal__label">Cabinet</span>
            <strong>{{ $grant->cabinet?->name ?? '—' }}</strong>
        </div>
        <div>
            <span class="drz-key-reveal__label">Propriétaire</span>
            <strong>{{ $grant->cabinet?->owner?->email ?? '—' }}</strong>
        </div>
        <div>
            <span class="drz-key-reveal__label">Type</span>
            <strong>{{ $grant->typeLabel() }}</strong>
        </div>
        <div>
            <span class="drz-key-reveal__label">État</span>
            <strong>{{ $grant->statusLabel() }}</strong>
        </div>
    </div>

    <p class="drz-key-reveal__note">
        Chaque consultation de cette clé est enregistrée dans le journal d’audit.
        La clé cesse de fonctionner dès qu’elle est utilisée ou révoquée.
    </p>
</div>
