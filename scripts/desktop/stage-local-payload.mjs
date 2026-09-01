#!/usr/bin/env node

/**
 * Stage the read-only payload that makes the desktop installer a working
 * offline application: the PHP runtime, the production Laravel tree, and an
 * empty migrated SQLite template.
 *
 * This replaces `stage-release-resources.mjs` for the local-first architecture
 * described in ADR-004. It stages no cloudflared binary and no runtime
 * Composer, because the shell no longer runs a tunnel, a LAN listener, or
 * Composer on the clinic's machine — shipping them would put unused network
 * binaries next to a patient database.
 *
 * Safety rules enforced here, not left to the operator:
 *   - No `.env` is ever copied. Each installation generates its own APP_KEY at
 *     first run; a shared key would make every clinic's encrypted data readable
 *     with one secret.
 *   - No development database, storage content, log, or backup is copied.
 *   - `public/hot` is never copied: its presence makes the application try to
 *     load assets from a Vite dev server that does not exist on a clinic PC.
 *   - The SQLite template is built from scratch and asserted to be empty of
 *     clinical rows before it is accepted.
 *
 * Usage:
 *   node scripts/desktop/stage-local-payload.mjs --php-runtime <dir> [--force]
 *
 *   --php-runtime <dir>   A prepared Windows PHP runtime directory containing
 *                         php.exe and ext/. Prepare it with
 *                         `--php-zip` + `--php-sha256`, or point at one you
 *                         already reviewed.
 *   --php-zip <file>      Official windows.php.net zip to extract instead.
 *   --php-sha256 <hex>    Required with --php-zip; compared before extraction.
 *   --force               Replace an existing staged payload.
 *   --skip-assets         Assume `npm run build` already ran.
 */

import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(scriptDirectory, '..', '..');
const resourcesRoot = path.join(repositoryRoot, 'src-tauri', 'resources');

/** Directories and files copied into the staged Laravel tree, in order. */
const APPLICATION_PATHS = [
    'app',
    'bootstrap/app.php',
    'bootstrap/providers.php',
    'config',
    'database/migrations',
    'database/seeders',
    'database/factories',
    'lang',
    'public',
    'resources/views',
    'routes',
    'artisan',
    'composer.json',
    'composer.lock',
];

/**
 * Never staged, whatever the source tree contains. Matched against the path
 * relative to the repository root, using forward slashes.
 */
const FORBIDDEN = [
    /^\.env/,
    /^public\/hot$/,
    /^public\/storage$/,
    /(^|\/)node_modules(\/|$)/,
    /(^|\/)\.git(\/|$)/,
    /\.sqlite$/,
    /\.sqlite-journal$/,
    /(^|\/)tests(\/|$)/,
    /(^|\/)storage\/(app|framework|logs)(\/|$)/,
    /\.log$/,
];

function fail(message) {
    process.stderr.write(`error: ${message}\n`);
    process.exit(1);
}

function parseArguments(argv) {
    const flags = new Set(['force', 'skip-assets', 'help']);
    const values = new Set(['php-runtime', 'php-zip', 'php-sha256']);
    const parsed = {};

    for (let index = 0; index < argv.length; index += 1) {
        const token = argv[index];

        if (!token.startsWith('--')) {
            fail(`unexpected argument: ${token}`);
        }

        const name = token.slice(2);

        if (flags.has(name)) {
            parsed[name] = true;
        } else if (values.has(name)) {
            index += 1;

            if (index >= argv.length) {
                fail(`--${name} requires a value`);
            }

            parsed[name] = argv[index];
        } else {
            fail(`unknown option: --${name}`);
        }
    }

    return parsed;
}

function isForbidden(relativePath) {
    const normalised = relativePath.split(path.sep).join('/');

    return FORBIDDEN.some((pattern) => pattern.test(normalised));
}

function copyTree(source, destination, relativeBase) {
    const stats = fs.statSync(source);
    const relative = path.relative(relativeBase, source);

    if (relative && isForbidden(relative)) {
        return 0;
    }

    if (stats.isSymbolicLink()) {
        // A staged symlink would either dangle or escape the install directory.
        return 0;
    }

    if (stats.isDirectory()) {
        fs.mkdirSync(destination, { recursive: true });
        let count = 0;

        for (const entry of fs.readdirSync(source)) {
            count += copyTree(
                path.join(source, entry),
                path.join(destination, entry),
                relativeBase,
            );
        }

        return count;
    }

    fs.mkdirSync(path.dirname(destination), { recursive: true });
    fs.copyFileSync(source, destination);

    return 1;
}

function sha256(file) {
    return crypto
        .createHash('sha256')
        .update(fs.readFileSync(file))
        .digest('hex');
}

function run(command, args, options = {}) {
    return execFileSync(command, args, {
        stdio: options.quiet ? 'pipe' : 'inherit',
        cwd: options.cwd ?? repositoryRoot,
        encoding: 'utf8',
        env: { ...process.env, ...(options.env ?? {}) },
    });
}

function removeIfPresent(target) {
    if (fs.existsSync(target)) {
        fs.rmSync(target, { recursive: true, force: true });
    }
}

// ---------------------------------------------------------------------------
// PHP runtime
// ---------------------------------------------------------------------------

function stagePhpRuntime(parsed) {
    const destination = path.join(resourcesRoot, 'php');
    let source = parsed['php-runtime'];

    if (parsed['php-zip']) {
        if (!parsed['php-sha256']) {
            fail(
                '--php-zip requires --php-sha256 so the download can be verified',
            );
        }

        const actual = sha256(parsed['php-zip']);

        if (actual !== parsed['php-sha256'].toLowerCase()) {
            fail(
                `PHP archive checksum mismatch\n  expected ${parsed['php-sha256']}\n  actual   ${actual}`,
            );
        }

        const extracted = path.join(resourcesRoot, '.php-extract');
        removeIfPresent(extracted);
        fs.mkdirSync(extracted, { recursive: true });
        run('unzip', ['-q', parsed['php-zip'], '-d', extracted]);
        source = extracted;
    }

    if (!source) {
        fail(
            'provide --php-runtime <dir> or --php-zip <file> --php-sha256 <hex>',
        );
    }

    const interpreter = path.join(source, 'php.exe');

    if (!fs.existsSync(interpreter)) {
        fail(`no php.exe in ${source}`);
    }

    if (!fs.existsSync(path.join(source, 'ext'))) {
        fail(`no ext/ directory in ${source}`);
    }

    removeIfPresent(destination);
    const files = copyTree(source, destination, source);

    // php.ini is generated per installation by the launcher, because the
    // correct absolute extension_dir is only known once installed.
    removeIfPresent(path.join(destination, 'php.ini'));

    return { files, interpreter: path.join(destination, 'php.exe') };
}

// ---------------------------------------------------------------------------
// Laravel application
// ---------------------------------------------------------------------------

function stageApplication(parsed) {
    const destination = path.join(resourcesRoot, 'laravel');

    if (!parsed['skip-assets']) {
        process.stdout.write('building production assets...\n');
        run('npm', ['run', 'build']);
    }

    if (
        !fs.existsSync(
            path.join(repositoryRoot, 'public', 'build', 'manifest.json'),
        )
    ) {
        fail(
            'public/build/manifest.json is missing; run `npm run build` first',
        );
    }

    removeIfPresent(destination);
    fs.mkdirSync(destination, { recursive: true });

    let files = 0;

    for (const relative of APPLICATION_PATHS) {
        const source = path.join(repositoryRoot, relative);

        if (!fs.existsSync(source)) {
            fail(`expected ${relative} in the checkout`);
        }

        files += copyTree(
            source,
            path.join(destination, relative),
            repositoryRoot,
        );
    }

    // Laravel writes its caches to the launcher-provided directories via
    // APP_*_CACHE, but the directory still has to exist in the tree.
    fs.mkdirSync(path.join(destination, 'bootstrap', 'cache'), {
        recursive: true,
    });
    fs.writeFileSync(
        path.join(destination, 'bootstrap', 'cache', '.gitignore'),
        '*\n!.gitignore\n',
    );

    process.stdout.write('installing production dependencies...\n');
    run(
        'composer',
        [
            'install',
            '--no-dev',
            '--optimize-autoloader',
            '--no-interaction',
            '--no-progress',
            '--no-scripts',
        ],
        { cwd: destination },
    );

    return { files, destination };
}

// ---------------------------------------------------------------------------
// SQLite template
// ---------------------------------------------------------------------------

function stageDatabaseTemplate(applicationRoot) {
    const initial = path.join(resourcesRoot, 'initial');
    fs.mkdirSync(initial, { recursive: true });
    const template = path.join(initial, 'database.sqlite');

    removeIfPresent(template);
    fs.writeFileSync(template, '');

    const scratch = fs.mkdtempSync(path.join(resourcesRoot, '.cache-'));

    process.stdout.write('migrating the empty database template...\n');
    run('php', ['artisan', 'migrate', '--force', '--no-interaction'], {
        cwd: applicationRoot,
        env: {
            APP_ENV: 'production',
            APP_DEBUG: 'false',
            // A throwaway key: the template holds no encrypted value, and every
            // installation generates its own key at first run.
            APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`,
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: template,
            LARAVEL_STORAGE_PATH: temporaryStorageTree(),
            // Every generated manifest is redirected out of the staged tree.
            // One written here would record this build machine's absolute
            // paths, ship them in the installer, and leave the application
            // unable to register its providers on a clinic PC.
            APP_SERVICES_CACHE: path.join(scratch, 'services.php'),
            APP_PACKAGES_CACHE: path.join(scratch, 'packages.php'),
            APP_CONFIG_CACHE: path.join(scratch, 'config.php'),
            APP_ROUTES_CACHE: path.join(scratch, 'routes.php'),
            APP_EVENTS_CACHE: path.join(scratch, 'events.php'),
        },
    });

    assertTemplateIsEmpty(applicationRoot, template);
    assertBootstrapCacheIsClean(applicationRoot);

    return template;
}

/**
 * Laravel will not boot against an empty storage root: it reports "Please
 * provide a valid cache path" rather than creating the tree itself. The
 * launcher creates the same set at runtime.
 */
function temporaryStorageTree() {
    const root = fs.mkdtempSync(path.join(resourcesRoot, '.storage-'));

    for (const relative of [
        'app/public',
        'app/private',
        'framework/cache/data',
        'framework/sessions',
        'framework/views',
        'logs',
    ]) {
        fs.mkdirSync(path.join(root, relative), { recursive: true });
    }

    return root;
}

/**
 * The staged tree must ship no generated manifest.
 *
 * `bootstrap/cache/services.php` and `packages.php` record absolute provider
 * paths. Written on the build machine they point at directories that do not
 * exist on a clinic PC, and Laravel then fails to register its own providers,
 * failing every request with `Class "view" does not exist`.
 */
function assertBootstrapCacheIsClean(applicationRoot) {
    const cache = path.join(applicationRoot, 'bootstrap', 'cache');
    const stale = fs
        .readdirSync(cache)
        .filter((entry) => entry.endsWith('.php'));

    for (const entry of stale) {
        fs.rmSync(path.join(cache, entry), { force: true });
    }

    if (stale.length > 0) {
        process.stdout.write(
            `removed ${stale.length} generated manifest(s) from bootstrap/cache
`,
        );
    }
}

/**
 * A template that shipped with one clinic's records would leak them into every
 * installation, so this is verified rather than assumed.
 */
function assertTemplateIsEmpty(applicationRoot, template) {
    const clinicalTables = [
        'patients',
        'appointments',
        'consultations',
        'users',
        'documents',
    ];

    for (const table of clinicalTables) {
        const output = run(
            'php',
            [
                '-r',
                `$p=new PDO("sqlite:".$argv[1]);$t=$p->query("SELECT name FROM sqlite_master WHERE type='table' AND name='".$argv[2]."'")->fetch();if(!$t){echo "absent";exit;}echo $p->query("SELECT COUNT(*) FROM ".$argv[2])->fetchColumn();`,
                template,
                table,
            ],
            { cwd: applicationRoot, quiet: true },
        ).trim();

        if (output !== 'absent' && output !== '0') {
            fail(
                `the database template contains ${output} row(s) in ${table}; refusing to stage it`,
            );
        }
    }
}

// ---------------------------------------------------------------------------

function main() {
    const parsed = parseArguments(process.argv.slice(2));

    if (parsed.help) {
        process.stdout.write(
            fs
                .readFileSync(fileURLToPath(import.meta.url), 'utf8')
                .split('*/')[0],
        );

        return;
    }

    const staged = path.join(resourcesRoot, 'laravel', 'artisan');

    if (fs.existsSync(staged) && !parsed.force) {
        fail('a payload is already staged; pass --force to replace it');
    }

    const php = stagePhpRuntime(parsed);
    process.stdout.write(`staged PHP runtime (${php.files} files)\n`);

    const application = stageApplication(parsed);
    process.stdout.write(
        `staged Laravel application (${application.files} files before vendor)\n`,
    );

    const template = stageDatabaseTemplate(application.destination);
    process.stdout.write(
        `staged database template (${fs.statSync(template).size} bytes)\n`,
    );

    // Scrub the temporary storage roots the migration run created.
    for (const entry of fs.readdirSync(resourcesRoot)) {
        if (
            entry.startsWith('.storage-') ||
            entry.startsWith('.cache-') ||
            entry === '.php-extract'
        ) {
            removeIfPresent(path.join(resourcesRoot, entry));
        }
    }

    process.stdout.write(
        '\npayload staged. Release builds will now pass the resource gate.\n',
    );
}

main();
