@props(['branding'])

<header {{ $attributes->class(['document-branding-header']) }}>
    <div class="document-branding-identity">
        @if ($branding['logo_url'])
            <img
                class="document-branding-logo"
                src="{{ $branding['logo_url'] }}"
                alt=""
            >
        @endif

        <div class="document-branding-copy">
            @if ($branding['clinic_name'])
                <h1>{{ $branding['clinic_name'] }}</h1>
            @endif

            @if ($branding['doctor_name'])
                <p class="document-branding-doctor">
                    {{ $branding['doctor_name'] }}
                    @if ($branding['specialty'])
                        <span>— {{ $branding['specialty'] }}</span>
                    @endif
                </p>
            @endif

            @if ($branding['order_number'])
                <p class="document-branding-meta">N° d’ordre : {{ $branding['order_number'] }}</p>
            @endif

            @if ($branding['address_line'])
                <p class="document-branding-meta">{{ $branding['address_line'] }}</p>
            @endif

            @if ($branding['phone'] || $branding['email'])
                <p class="document-branding-meta">
                    @if ($branding['phone'])
                        Tél. {{ $branding['phone'] }}
                    @endif
                    @if ($branding['phone'] && $branding['email'])
                        <span> · </span>
                    @endif
                    @if ($branding['email'])
                        {{ $branding['email'] }}
                    @endif
                </p>
            @endif
        </div>
    </div>

    <div class="document-branding-document">
        {{ $slot }}
    </div>
</header>
