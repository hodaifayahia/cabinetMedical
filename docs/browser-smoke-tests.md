# Tests navigateur Playwright

La suite E2E vérifie le parcours navigateur le plus critique qui soit entièrement déterministe sans service externe :

1. une installation vide propose la création du premier propriétaire ;
2. le propriétaire est créé et authentifié par le vrai formulaire Fortify/Inertia ;
3. la page **Cabinet & documents** est accessible ;
4. l’identité du cabinet est enregistrée, puis relue après rechargement ;
5. la spécialité initiale reste verrouillée.

Le test utilise les ressources Vite réellement compilées et un serveur Laravel démarré par Playwright. Il ne remplace pas les tests PHPUnit/Vitest.

## Isolation des données

`npm run test:e2e` prépare une base SQLite dédiée sous :

```text
storage/framework/testing/playwright/database.sqlite
```

Le script refuse un chemin d’exécution différent de cette zone. Il ne supprime que cette base E2E et ses éventuels fichiers SQLite `-wal`/`-shm`. Les sauvegardes sont redirigées vers `storage/framework/testing/playwright/backups` : la suite ne lit, ne restaure et ne supprime jamais les archives de `storage/app/private/backups`.

Google Drive, le tunnel distant, ONLYOFFICE, les binaires Tauri et les ressources d’un installateur de production sont volontairement hors de ce smoke test. Ils nécessitent leurs propres fixtures ou artefacts signés ; leur absence ne produit pas un faux succès ni un test masqué.

## Exécution locale

Préparer une installation Laravel normale, puis installer Chromium une fois :

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
npm ci
npm run test:e2e:install
```

Lancer ensuite le contrôle complet :

```powershell
npm run test:e2e
```

Le port `4173` est réservé au serveur de test. Il peut être remplacé par un port libre explicite :

```powershell
$env:PLAYWRIGHT_PORT = '43173'
npm run test:e2e
```

Playwright refuse de réutiliser un serveur déjà présent sur ce port afin de ne jamais tester par erreur une installation réelle. Les traces, captures et le rapport HTML sont générés sous `test-results/playwright` et `playwright-report`.

Références : [serveur web Playwright](https://playwright.dev/docs/test-webserver) et [exécution en CI](https://playwright.dev/docs/ci).

## Garde-fou Windows

`.github/workflows/browser-smoke-windows.yml` exécute ce même scénario sur `windows-latest` avec PHP 8.3, Node 22 et Chromium. Le rapport et les traces sont publiés comme artefact GitHub Actions, y compris lors d’un échec.
