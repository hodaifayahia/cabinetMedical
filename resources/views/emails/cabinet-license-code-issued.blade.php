@component('mail::message')
# Votre code d’activation Drclick

Bonjour {{ $ownerName }},

Une licence **{{ $licensePlan }}** a été préparée pour le cabinet
**{{ $cabinetName }}**.

@if ($requiresAccountSetup)
Créez d’abord votre compte Drclick avec cette adresse e-mail. Le code sera associé à votre cabinet lors de votre inscription.
@endif

Saisissez ce code une seule fois dans l’écran d’activation :

@component('mail::panel')
{{ $licenseCode }}
@endcomponent

Ce code est réservé à votre cabinet. Si un nouveau code est généré, celui-ci cessera de fonctionner.

@component('mail::button', ['url' => $activationUrl])
{{ $requiresAccountSetup ? 'Créer mon compte' : 'Activer ma licence' }}
@endcomponent

Merci de votre confiance,<br>
L’équipe {{ config('app.name') }}
@endcomponent
