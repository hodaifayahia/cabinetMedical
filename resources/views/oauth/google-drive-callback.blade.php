<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $success ? 'Google Drive connecte' : 'Connexion non terminee' }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { display: grid; min-height: 100vh; margin: 0; place-items: center; background: #eef7f5; color: #00424a; }
        main { width: min(34rem, calc(100% - 3rem)); padding: 2rem; border: 1px solid #d5e4e2; border-radius: 1rem; background: white; box-shadow: 0 1rem 3rem rgb(0 66 74 / 10%); }
        h1 { margin-top: 0; font-size: 1.5rem; }
        p { line-height: 1.6; }
        @media (prefers-color-scheme: dark) { body { background: #002f35; color: #eef7f5; } main { border-color: #45686b; background: #00424a; } }
    </style>
</head>
<body>
<main>
    @if ($success)
        <h1>Google Drive est connect&eacute;</h1>
        <p>La connexion a &eacute;t&eacute; enregistr&eacute;e dans Drclick.</p>
    @else
        <h1>Connexion Google Drive non termin&eacute;e</h1>
        <p>La demande a expir&eacute;, a d&eacute;j&agrave; &eacute;t&eacute; utilis&eacute;e ou n&rsquo;a pas pu &ecirc;tre valid&eacute;e.</p>
    @endif
    <p>Vous pouvez fermer cet onglet et revenir &agrave; Drclick.</p>
</main>
</body>
</html>
