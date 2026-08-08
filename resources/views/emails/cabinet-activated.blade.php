@component('mail::message')
# Votre cabinet est activé

Bonjour {{ $ownerName }},

Bonne nouvelle : votre cabinet **{{ $cabinetName }}** a été activé. Vous pouvez
désormais vous connecter et commencer à utiliser l'application.

**Licence attribuée :** {{ $licensePlan ?? 'À vie' }}

@if ($expiresAt)
**Valable jusqu'au :** {{ $expiresAt->timezone(config('app.timezone'))->translatedFormat('d F Y à H:i') }}
@else
Cette licence est sans date d'expiration.
@endif

@component('mail::button', ['url' => $loginUrl])
Se connecter
@endcomponent

Merci de votre confiance,<br>
L'équipe {{ config('app.name') }}
@endcomponent
