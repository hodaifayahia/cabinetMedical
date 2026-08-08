@component('mail::message')
# Votre licence a été mise à jour

Bonjour {{ $ownerName }},

La licence du cabinet **{{ $cabinetName }}** est maintenant :
**{{ $licensePlan }}**.

@if ($expiresAt)
Elle est valable jusqu'au
**{{ $expiresAt->timezone(config('app.timezone'))->translatedFormat('d F Y à H:i') }}**.
@else
Elle est active sans date d'expiration.
@endif

@component('mail::button', ['url' => $loginUrl])
Se connecter
@endcomponent

Merci de votre confiance,<br>
L'équipe {{ config('app.name') }}
@endcomponent
