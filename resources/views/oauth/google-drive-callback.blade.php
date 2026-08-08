<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $success ? 'Google Drive connecte' : 'Connexion non terminee' }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { display: grid; min-height: 100vh; margin: 0; place-items: center; background: #f4f7fb; color: #14213d; }
        main { width: min(34rem, calc(100% - 3rem)); padding: 2rem; border: 1px solid #dce3ed; border-radius: 1rem; background: white; box-shadow: 0 1rem 3rem rgb(20 33 61 / 10%); }
        h1 { margin-top: 0; font-size: 1.5rem; }
        p { line-height: 1.6; }
        @media (prefers-color-scheme: dark) { body { background: #101827; color: #edf2f7; } main { border-color: #334155; background: #172033; } }
    </style>
</head>
<body>
<main>
    @if ($success)
        <h1>Google Drive est connect&eacute;</h1>
        <p>La connexion a &eacute;t&eacute; enregistr&eacute;e dans DrClickDz.</p>
    @else
        <h1>Connexion Google Drive non termin&eacute;e</h1>
        <p>La demande a expir&eacute;, a d&eacute;j&agrave; &eacute;t&eacute; utilis&eacute;e ou n&rsquo;a pas pu &ecirc;tre valid&eacute;e.</p>
    @endif
    <p>Vous pouvez fermer cet onglet et revenir &agrave; DrClickDz.</p>
</main>
</body>
</html>
