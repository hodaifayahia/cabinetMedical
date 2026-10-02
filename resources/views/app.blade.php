<!DOCTYPE html>
{{-- Light-only product: the `dark` class is never emitted, so the first paint
     already matches the theme the client keeps. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="referrer" content="no-referrer">
        <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">

        {{-- The desktop shell must never render the public marketing page. --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                if (globalThis.isTauri && window.location.pathname === '/') {
                    window.location.replace('/login');
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style nonce="{{ Vite::cspNonce() }}">
            html {
                background-color: oklch(1 0 0);
            }
        </style>

        <link rel="icon" href="/brand/drclick-mark.png?v=20261002" type="image/png" sizes="512x512">
        <link rel="apple-touch-icon" href="/brand/drclick-mark.png?v=20261002" sizes="512x512">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-nonce-bound-inertia-head>
            <title>{{ config('app.name', 'Drclick') }}</title>
        </x-nonce-bound-inertia-head>
    </head>
    <body class="font-sans antialiased">
        <x-nonce-bound-inertia-app />
    </body>
</html>
