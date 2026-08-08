@component('mail::message')
# Votre code d’activation DrClickDz

Bonjour {{ $ownerName }},

Une licence **{{ $licensePlan }}** a été préparée pour le cabinet
**{{ $cabinetName }}**.

Saisissez ce code une seule fois dans l’écran d’activation :

@component('mail::panel')
{{ $licenseCode }}
@endcomponent

Ce code est réservé à votre cabinet et ne peut être utilisé que par son
propriétaire. Si un nouveau code est généré, celui-ci cessera de fonctionner.

@component('mail::button', ['url' => $activationUrl])
Activer ma licence
@endcomponent

Merci de votre confiance,<br>
L’équipe {{ config('app.name') }}
@endcomponent
