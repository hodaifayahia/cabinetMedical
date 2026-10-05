@component('mail::message')
# Réinitialiser votre mot de passe

Bonjour {{ $name }},

Voici votre code pour choisir un nouveau mot de passe dans l'application {{ config('app.name') }} :

@component('mail::panel')
<div style="font-size: 28px; font-weight: 700; letter-spacing: 8px; text-align: center;">{{ $code }}</div>
@endcomponent

Il est valable {{ $minutes }} minutes. Si vous n'avez rien demandé, ignorez ce message : votre mot de passe ne change pas.

---

<div dir="rtl" style="text-align: right;">

مرحبًا {{ $name }}،

هذا هو رمزك لاختيار كلمة مرور جديدة في تطبيق {{ config('app.name') }}: <strong dir="ltr">{{ $code }}</strong>

الرمز صالح لمدة {{ $minutes }} دقيقة. إذا لم تطلب ذلك، تجاهل هذه الرسالة؛ كلمة مرورك لن تتغير.

</div>

L'équipe {{ config('app.name') }}
@endcomponent
