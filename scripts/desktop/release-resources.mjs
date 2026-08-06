import { spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { constants as fsConstants } from 'node:fs';
import {
    access,
    copyFile,
    lstat,
    mkdir,
    mkdtemp,
    open,
    readFile,
    realpath,
    readdir,
    rename,
    rm,
    stat,
    writeFile,
} from 'node:fs/promises';
import path from 'node:path';

export const RESOURCE_SCHEMA_VERSION = 1;
export const PHP_REVIEW_PRODUCT = 'php-windows-runtime';
export const RESOURCE_MANIFEST = 'release-resources.manifest.json';
export const LARAVEL_MANIFEST = 'release.manifest.json';
export const PHP_MANIFEST = 'php-runtime.manifest.json';
export const DATABASE_MANIFEST = 'database.manifest.json';
export const MIGRATION_CONTRACT = 'migration-contract.json';
export const MIGRATION_HELPER_PATH =
    'app/Console/Commands/NativeMigrationGate.php';

export const REQUIRED_PHP_EXTENSIONS = Object.freeze([
    'ctype',
    'curl',
    'date',
    'dom',
    'fileinfo',
    'filter',
    'gd',
    'hash',
    'iconv',
    'intl',
    'json',
    'libxml',
    'mbstring',
    'openssl',
    'pcre',
    'pdo',
    'pdo_sqlite',
    'phar',
    'session',
    'simplexml',
    'sodium',
    'spl',
    'sqlite3',
    'tokenizer',
    'xml',
    'xmlreader',
    'xmlwriter',
    'zip',
    'zlib',
]);

const REQUIRED_LARAVEL_FILES = Object.freeze([
    'artisan',
    'config/queue.php',
    'routes/console.php',
    'database/migrations/0001_01_01_000002_create_jobs_table.php',
    'public/index.php',
    'vendor/autoload.php',
    'vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php',
    'vendor/laravel/framework/src/Illuminate/Queue/Worker.php',
    'vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleWorkCommand.php',
    'vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleRunCommand.php',
    'vendor/laravel/framework/src/Illuminate/Console/Scheduling/Schedule.php',
    'app/Console/Commands/NativeApplyOfflineRestore.php',
    MIGRATION_HELPER_PATH,
    'app/Backups/OfflineRestoreExecutor.php',
    'app/Backups/PreparedRestore.php',
    'app/Backups/SupervisorOfflineRestoreGuard.php',
]);

const ALLOWED_NONEMPTY_DATABASE_TABLES = new Set([
    'acts',
    'bilan_types',
    'cabinet_settings',
    'consultation_fees',
    'exams',
    'medications',
    'payment_methods',
    'permissions',
    'role_has_permissions',
    'roles',
]);

const REQUIRED_REFERENCE_TABLES = Object.freeze([
    'exams',
    'medications',
    'permissions',
    'roles',
]);

const REQUIRED_EMPTY_DATABASE_TABLES = Object.freeze([
    'application_settings',
    'appointments',
    'backup_records',
    'cloud_connections',
    'consultations',
    'documents',
    'encounters',
    'failed_jobs',
    'jobs',
    'licenses',
    'patients',
    'prescriptions',
    'tunnel_settings',
    'upload_sessions',
    'users',
]);

const SAFE_WINDOWS_ENVIRONMENT_NAMES = new Set([
    'COMSPEC',
    'NUMBER_OF_PROCESSORS',
    'OS',
    'PATH',
    'PATHEXT',
    'PROCESSOR_ARCHITECTURE',
    'PROCESSOR_IDENTIFIER',
    'SYSTEMDRIVE',
    'SYSTEMROOT',
    'TEMP',
    'TMP',
    'WINDIR',
]);

const WINDOWS_RESERVED_NAMES =
    /^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i;
const INVALID_WINDOWS_CHARS = /[<>:"|?*\u0000-\u001f]/;
const DEV_ARTIFACT_SUFFIXES =
    /(?:\.bak|\.diff|\.map|\.old|\.orig|\.patch|\.rej|\.save|\.swo|\.swp|\.temp|\.tmp|~)$/i;
const DOTENV_NAME = /^\.env(?:\..+)?$/i;
const LOWER_SHA256 = /^[0-9a-f]{64}$/;

function fail(message) {
    throw new Error(message);
}

export function assertWindowsHost(platform = process.platform) {
    if (platform !== 'win32') {
        fail(
            'release resources must be staged on the controlled Windows build host',
        );
    }
}

export function parseCliArguments(
    argv,
    booleanNames = new Set(),
    valueNames = null,
) {
    const parsed = {};

    for (let index = 0; index < argv.length; index += 1) {
        const token = argv[index];

        if (!token.startsWith('--')) {
            fail(`unexpected positional argument: ${token}`);
        }

        const name = token.slice(2);

        if (Object.hasOwn(parsed, name)) {
            fail(`duplicate option: --${name}`);
        }

        if (
            !booleanNames.has(name) &&
            valueNames !== null &&
            !valueNames.has(name)
        ) {
            fail(`unknown option: --${name}`);
        }

        if (booleanNames.has(name)) {
            parsed[name] = true;
            continue;
        }

        const value = argv[index + 1];

        if (value === undefined || value.startsWith('--')) {
            fail(`missing value for --${name}`);
        }

        parsed[name] = value;
        index += 1;
    }

    return parsed;
}

export function requireCliArguments(parsed, names) {
    for (const name of names) {
        if (typeof parsed[name] !== 'string' || parsed[name].trim() === '') {
            fail(`--${name} is required`);
        }
    }
}

function assertExactKeys(value, keys, label) {
    if (value === null || Array.isArray(value) || typeof value !== 'object') {
        fail(`${label} must be a JSON object`);
    }

    const actual = Object.keys(value).sort();
    const expected = [...keys].sort();

    if (
        actual.length !== expected.length ||
        actual.some((key, index) => key !== expected[index])
    ) {
        fail(`${label} must contain exactly: ${expected.join(', ')}`);
    }
}

function assertLowerSha256(value, label) {
    if (typeof value !== 'string' || !LOWER_SHA256.test(value)) {
        fail(`${label} must be a lowercase SHA-256 digest`);
    }
}

function assertPortableSegment(segment, relativePath) {
    if (
        segment === '' ||
        segment === '.' ||
        segment === '..' ||
        segment.endsWith('.') ||
        segment.endsWith(' ') ||
        INVALID_WINDOWS_CHARS.test(segment) ||
        WINDOWS_RESERVED_NAMES.test(segment)
    ) {
        fail(`non-portable Windows path in release input: ${relativePath}`);
    }
}

export function normalizeReleasePath(relativePath) {
    if (typeof relativePath !== 'string' || relativePath === '') {
        fail('release path must be a non-empty string');
    }

    const normalized = relativePath.replaceAll('\\', '/');

    if (normalized.startsWith('/') || /^[a-z]:/i.test(normalized)) {
        fail(
            `absolute path is not permitted in a release manifest: ${relativePath}`,
        );
    }

    const segments = normalized.split('/');

    for (const segment of segments) {
        assertPortableSegment(segment, normalized);
    }

    if (segments.join('/') !== normalized) {
        fail(`non-canonical path in release manifest: ${relativePath}`);
    }

    return normalized;
}

export function isForbiddenReleaseSourcePath(relativePath) {
    const normalized = relativePath.replaceAll('\\', '/');
    const name = normalized.split('/').at(-1) ?? '';

    return (
        DEV_ARTIFACT_SUFFIXES.test(name) ||
        /^#.*#$/.test(name) ||
        /(?:^|\/)(?:__fixtures__|__tests__|fixtures?|tests?)(?:\/|$)/i.test(
            normalized,
        ) ||
        /(?:\.spec|\.test)\.[^.]+$/i.test(name)
    );
}

function isWithin(parent, candidate) {
    const relative = path.relative(parent, candidate);

    return (
        relative === '' ||
        (!relative.startsWith(`..${path.sep}`) &&
            relative !== '..' &&
            !path.isAbsolute(relative))
    );
}

async function pathExists(target) {
    try {
        await lstat(target);

        return true;
    } catch (error) {
        if (error?.code === 'ENOENT') {
            return false;
        }

        throw error;
    }
}

export async function assertRegularFile(target, label = target) {
    const info = await lstat(target).catch((error) => {
        fail(`${label} is unavailable: ${error.message}`);
    });

    if (info.isSymbolicLink() || !info.isFile()) {
        fail(`${label} must be a regular file, not a link or special file`);
    }
}

export async function assertPlainDirectory(target, label = target) {
    const info = await lstat(target).catch((error) => {
        fail(`${label} is unavailable: ${error.message}`);
    });

    if (info.isSymbolicLink() || !info.isDirectory()) {
        fail(`${label} must be a real directory, not a link or special file`);
    }
}

export async function assertSafeExistingPathChain(target) {
    const resolved = path.resolve(target);
    const parsed = path.parse(resolved);
    const components = resolved
        .slice(parsed.root.length)
        .split(path.sep)
        .filter(Boolean);
    let current = parsed.root;

    for (const component of components) {
        current = path.join(current, component);

        if (!(await pathExists(current))) {
            break;
        }

        const info = await lstat(current);

        if (info.isSymbolicLink()) {
            fail(
                `release path crosses a symbolic link or reparse point: ${current}`,
            );
        }
    }
}

export async function inspectTree(root, { exclude = new Set() } = {}) {
    await assertPlainDirectory(root, root);
    const canonicalRoot = await realpath(root);
    const files = {};
    const directories = [];
    const caseFolded = new Map();

    async function visit(relativeDirectory) {
        const absoluteDirectory = relativeDirectory
            ? path.join(root, ...relativeDirectory.split('/'))
            : root;
        const entries = await readdir(absoluteDirectory, {
            withFileTypes: true,
        });
        entries.sort((left, right) =>
            left.name.localeCompare(right.name, 'en'),
        );

        for (const entry of entries) {
            const relative = normalizeReleasePath(
                relativeDirectory
                    ? `${relativeDirectory}/${entry.name}`
                    : entry.name,
            );

            if (exclude.has(relative)) {
                continue;
            }

            const folded = relative.toLocaleLowerCase('en-US');
            const collision = caseFolded.get(folded);

            if (collision !== undefined && collision !== relative) {
                fail(
                    `case-insensitive release path collision: ${collision} and ${relative}`,
                );
            }

            caseFolded.set(folded, relative);

            const absolute = path.join(root, ...relative.split('/'));
            const info = await lstat(absolute);

            if (info.isSymbolicLink()) {
                fail(
                    `symbolic links and reparse points are forbidden in release resources: ${relative}`,
                );
            }

            if (info.isDirectory()) {
                const resolved = await realpath(absolute);

                if (!isWithin(canonicalRoot, resolved)) {
                    fail(`release directory escapes its root: ${relative}`);
                }

                directories.push(relative);
                await visit(relative);
                continue;
            }

            if (!info.isFile()) {
                fail(
                    `special files are forbidden in release resources: ${relative}`,
                );
            }

            const resolved = await realpath(absolute);

            if (!isWithin(canonicalRoot, resolved)) {
                fail(`release file escapes its root: ${relative}`);
            }

            files[relative] = await sha256File(absolute);
        }
    }

    await visit('');
    directories.sort();

    return { directories, files: sortObject(files) };
}

export async function sha256File(target) {
    const bytes = await readFile(target);

    return createHash('sha256').update(bytes).digest('hex');
}

function sortObject(value) {
    return Object.fromEntries(
        Object.entries(value).sort(([left], [right]) =>
            compareUtf8Bytes(left, right),
        ),
    );
}

export function compareUtf8Bytes(left, right) {
    return Buffer.compare(
        Buffer.from(left, 'utf8'),
        Buffer.from(right, 'utf8'),
    );
}

function assertApplicationVersion(version) {
    const semver =
        /^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/;

    if (typeof version !== 'string' || !semver.test(version)) {
        fail(
            `application version must be a canonical semantic version: ${version}`,
        );
    }
}

function migrationEntriesFromLaravelFiles(laravelFiles) {
    return Object.entries(laravelFiles)
        .filter(([relative]) => relative.startsWith('database/migrations/'))
        .map(([migrationPath, sha256]) => ({ path: migrationPath, sha256 }))
        .sort((left, right) => compareUtf8Bytes(left.path, right.path));
}

export function canonicalMigrationSetSha256(migrations) {
    const hasher = createHash('sha256');

    for (const migration of migrations) {
        hasher.update(migration.path, 'utf8');
        hasher.update(Buffer.from([0]));
        hasher.update(migration.sha256, 'ascii');
        hasher.update('\n', 'ascii');
    }

    return hasher.digest('hex');
}

export function validateMigrationContractData(
    contract,
    {
        applicationVersion = null,
        initialDatabaseSha256,
        migrationCount,
        laravelFiles,
    },
) {
    assertExactKeys(
        contract,
        [
            'schema_version',
            'application_version',
            'initial_database_sha256',
            'migration_helper',
            'migrations',
            'migration_set_sha256',
        ],
        'migration contract',
    );

    if (contract.schema_version !== RESOURCE_SCHEMA_VERSION) {
        fail('migration contract has an unsupported schema');
    }

    assertApplicationVersion(contract.application_version);

    if (
        applicationVersion !== null &&
        contract.application_version !== applicationVersion
    ) {
        fail(
            `migration contract application version ${contract.application_version} does not match ${applicationVersion}`,
        );
    }

    assertLowerSha256(
        contract.initial_database_sha256,
        'migration contract initial database hash',
    );

    if (contract.initial_database_sha256 !== initialDatabaseSha256) {
        fail('migration contract does not bind the packaged initial database');
    }

    assertExactKeys(
        contract.migration_helper,
        ['path', 'sha256'],
        'migration contract helper',
    );

    if (contract.migration_helper.path !== MIGRATION_HELPER_PATH) {
        fail(
            `migration contract helper must be the fixed path ${MIGRATION_HELPER_PATH}`,
        );
    }

    assertLowerSha256(
        contract.migration_helper.sha256,
        'migration contract helper hash',
    );

    if (
        contract.migration_helper.sha256 !== laravelFiles[MIGRATION_HELPER_PATH]
    ) {
        fail(
            'migration contract helper hash does not match the Laravel inventory',
        );
    }

    if (
        !Array.isArray(contract.migrations) ||
        contract.migrations.length === 0
    ) {
        fail('migration contract must contain an ordered migration list');
    }

    const expectedMigrations = migrationEntriesFromLaravelFiles(laravelFiles);

    if (
        expectedMigrations.length !== migrationCount ||
        contract.migrations.length !== migrationCount
    ) {
        fail(
            'migration contract count does not match the Laravel inventory and initial database',
        );
    }

    let previousPath = null;
    const seen = new Set();

    for (const [index, migration] of contract.migrations.entries()) {
        assertExactKeys(
            migration,
            ['path', 'sha256'],
            `migration contract entry ${index}`,
        );
        const normalized = normalizeReleasePath(migration.path);
        const basename = normalized.slice('database/migrations/'.length);

        if (
            normalized !== migration.path ||
            !normalized.startsWith('database/migrations/') ||
            basename.includes('/') ||
            !/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/.test(basename)
        ) {
            fail(
                `migration contract contains an invalid migration path: ${migration.path}`,
            );
        }

        const folded = normalized.toLocaleLowerCase('en-US');

        if (seen.has(folded)) {
            fail(
                `migration contract contains a duplicate or case-colliding path: ${normalized}`,
            );
        }

        seen.add(folded);

        assertLowerSha256(
            migration.sha256,
            `migration contract hash for ${normalized}`,
        );

        if (
            previousPath !== null &&
            compareUtf8Bytes(previousPath, normalized) >= 0
        ) {
            fail('migration contract entries must be in strict lexical order');
        }

        previousPath = normalized;

        const expected = expectedMigrations[index];

        if (
            expected?.path !== normalized ||
            expected.sha256 !== migration.sha256
        ) {
            fail(
                `migration contract entry does not match the Laravel inventory: ${normalized}`,
            );
        }
    }

    assertLowerSha256(
        contract.migration_set_sha256,
        'canonical migration set hash',
    );

    if (
        canonicalMigrationSetSha256(contract.migrations) !==
        contract.migration_set_sha256
    ) {
        fail(
            'migration contract canonical set hash does not match its entries',
        );
    }

    return contract;
}

async function writeJson(target, value) {
    await writeFile(target, `${JSON.stringify(value, null, 2)}\n`, {
        encoding: 'utf8',
        flag: 'wx',
    });
}

async function readJson(target, label) {
    await assertRegularFile(target, label);
    let parsed;

    try {
        parsed = JSON.parse(await readFile(target, 'utf8'));
    } catch (error) {
        fail(`${label} is invalid JSON: ${error.message}`);
    }

    return parsed;
}

function validateInventoryShape(manifest, label) {
    if (
        !Array.isArray(manifest.directories) ||
        manifest.directories.some((item) => typeof item !== 'string')
    ) {
        fail(`${label}.directories must be an array of paths`);
    }

    if (
        manifest.files === null ||
        Array.isArray(manifest.files) ||
        typeof manifest.files !== 'object'
    ) {
        fail(`${label}.files must be an object of file hashes`);
    }

    const seen = new Set();

    for (const relative of manifest.directories) {
        const normalized = normalizeReleasePath(relative);

        if (
            normalized !== relative ||
            seen.has(relative.toLocaleLowerCase('en-US'))
        ) {
            fail(
                `${label} contains a duplicate or non-canonical directory: ${relative}`,
            );
        }

        seen.add(relative.toLocaleLowerCase('en-US'));
    }

    for (const [relative, digest] of Object.entries(manifest.files)) {
        const normalized = normalizeReleasePath(relative);

        if (
            normalized !== relative ||
            seen.has(relative.toLocaleLowerCase('en-US'))
        ) {
            fail(
                `${label} contains a duplicate or non-canonical file: ${relative}`,
            );
        }

        assertLowerSha256(digest, `${label}.files[${relative}]`);
        seen.add(relative.toLocaleLowerCase('en-US'));
    }
}

export async function validateManifestInventory(
    root,
    manifest,
    selfRelative,
    label,
) {
    validateInventoryShape(manifest, label);
    const actual = await inspectTree(root, {
        exclude: selfRelative === null ? new Set() : new Set([selfRelative]),
    });
    const expectedDirectories = [...manifest.directories].sort();

    if (
        JSON.stringify(actual.directories) !==
        JSON.stringify(expectedDirectories)
    ) {
        const expected = new Set(expectedDirectories);
        const observed = new Set(actual.directories);
        const unexpected = actual.directories.filter(
            (item) => !expected.has(item),
        );
        const missing = expectedDirectories.filter(
            (item) => !observed.has(item),
        );
        fail(
            `${label} directory inventory mismatch (unexpected: ${unexpected.join(', ') || 'none'}; missing: ${missing.join(', ') || 'none'})`,
        );
    }

    const expectedFiles = sortObject(manifest.files);
    const actualNames = Object.keys(actual.files);
    const expectedNames = Object.keys(expectedFiles);

    if (JSON.stringify(actualNames) !== JSON.stringify(expectedNames)) {
        const expected = new Set(expectedNames);
        const observed = new Set(actualNames);
        const unexpected = actualNames.filter((item) => !expected.has(item));
        const missing = expectedNames.filter((item) => !observed.has(item));
        fail(
            `${label} file inventory mismatch (unexpected: ${unexpected.join(', ') || 'none'}; missing: ${missing.join(', ') || 'none'})`,
        );
    }

    for (const relative of expectedNames) {
        if (actual.files[relative] !== expectedFiles[relative]) {
            fail(`${label} hash mismatch: ${relative}`);
        }
    }
}

function validatePhpVersion(version) {
    if (
        typeof version !== 'string' ||
        !/^8\.(?:[3-9]|[1-9][0-9])\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(version)
    ) {
        fail(
            `reviewed PHP version must be PHP 8.3 or newer in the 8.x series; received ${version}`,
        );
    }
}

function normalizeExtensions(extensions, label) {
    if (
        !Array.isArray(extensions) ||
        extensions.some((item) => typeof item !== 'string')
    ) {
        fail(`${label} must be an array of extension names`);
    }

    const normalized = extensions
        .map((item) => item.toLocaleLowerCase('en-US'))
        .sort();

    if (
        new Set(normalized).size !== normalized.length ||
        JSON.stringify(extensions) !== JSON.stringify(normalized)
    ) {
        fail(`${label} must be lowercase, unique, and sorted`);
    }

    return normalized;
}

export function validatePhpExtensions(extensions) {
    const normalized = normalizeExtensions(extensions, 'PHP extensions');
    const available = new Set(normalized);
    const missing = REQUIRED_PHP_EXTENSIONS.filter(
        (extension) => !available.has(extension),
    );

    if (missing.length > 0) {
        fail(
            `PHP runtime is missing required extensions: ${missing.join(', ')}`,
        );
    }

    return normalized;
}

export function validatePhpReviewManifest(manifest) {
    assertExactKeys(
        manifest,
        [
            'schema_version',
            'product',
            'version',
            'architecture',
            'extensions',
            'directories',
            'files',
        ],
        'PHP review manifest',
    );

    if (
        manifest.schema_version !== RESOURCE_SCHEMA_VERSION ||
        manifest.product !== PHP_REVIEW_PRODUCT
    ) {
        fail('PHP review manifest has an unsupported schema or product');
    }

    validatePhpVersion(manifest.version);

    if (manifest.architecture !== 'x64') {
        fail('PHP runtime must be the reviewed x64 Windows build');
    }

    validatePhpExtensions(manifest.extensions);
    validateInventoryShape(manifest, 'PHP review manifest');

    if (!Object.hasOwn(manifest.files, 'php.exe')) {
        fail('PHP review manifest does not contain php.exe');
    }

    return manifest;
}

function validatePhpProbe(probe, expectedReview) {
    assertExactKeys(
        probe,
        [
            'version',
            'version_id',
            'os_family',
            'sapi',
            'architecture_bits',
            'extensions',
            'loaded_ini',
            'scanned_ini',
        ],
        'PHP runtime probe',
    );

    if (
        !Number.isInteger(probe.version_id) ||
        probe.version_id < 80300 ||
        probe.version_id >= 90000 ||
        probe.os_family !== 'Windows' ||
        probe.sapi !== 'cli' ||
        probe.architecture_bits !== 64
    ) {
        fail(
            'supplied PHP must be a 64-bit Windows CLI runtime on PHP 8.3 or newer in the 8.x series',
        );
    }

    validatePhpVersion(probe.version);
    const extensions = validatePhpExtensions(probe.extensions);

    if (expectedReview) {
        if (
            probe.version !== expectedReview.version ||
            JSON.stringify(extensions) !==
                JSON.stringify(expectedReview.extensions)
        ) {
            fail(
                'PHP runtime probe does not match the reviewed version and exact extension set',
            );
        }
    }

    return extensions;
}

function assertIniContained(runtimeRoot, probe) {
    const root = path.resolve(runtimeRoot);
    const iniFiles = [];

    if (
        typeof probe.loaded_ini === 'string' &&
        probe.loaded_ini.trim() !== ''
    ) {
        iniFiles.push(probe.loaded_ini.trim());
    }

    if (
        typeof probe.scanned_ini === 'string' &&
        probe.scanned_ini.trim() !== ''
    ) {
        iniFiles.push(
            ...probe.scanned_ini
                .split(',')
                .map((item) => item.trim())
                .filter(Boolean),
        );
    }

    for (const iniFile of iniFiles) {
        const resolved = path.resolve(iniFile);

        if (!isWithin(root, resolved)) {
            fail(
                `PHP loaded configuration outside the reviewed runtime: ${iniFile}`,
            );
        }
    }
}

export async function runCommand(
    executable,
    args,
    {
        cwd,
        env,
        label = executable,
        timeoutMs = 120_000,
        maxOutputBytes = 4 * 1024 * 1024,
    } = {},
) {
    return await new Promise((resolve, reject) => {
        const child = spawn(executable, args, {
            cwd,
            env,
            shell: false,
            windowsHide: true,
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        let stdout = Buffer.alloc(0);
        let stderr = Buffer.alloc(0);
        let settled = false;
        const timer = setTimeout(() => {
            child.kill('SIGKILL');

            if (!settled) {
                settled = true;
                reject(new Error(`${label} exceeded ${timeoutMs} ms`));
            }
        }, timeoutMs);
        const append = (current, chunk) => {
            const next = Buffer.concat([current, chunk]);

            if (next.length > maxOutputBytes) {
                child.kill('SIGKILL');

                if (!settled) {
                    settled = true;
                    clearTimeout(timer);
                    reject(
                        new Error(
                            `${label} produced more than ${maxOutputBytes} bytes of output`,
                        ),
                    );
                }
            }

            return next;
        };
        child.stdout.on('data', (chunk) => {
            stdout = append(stdout, chunk);
        });
        child.stderr.on('data', (chunk) => {
            stderr = append(stderr, chunk);
        });
        child.once('error', (error) => {
            clearTimeout(timer);

            if (!settled) {
                settled = true;
                reject(new Error(`${label} could not start: ${error.message}`));
            }
        });
        child.once('close', (code, signal) => {
            clearTimeout(timer);

            if (settled) {
                return;
            }

            settled = true;
            const result = {
                stdout: stdout.toString('utf8'),
                stderr: stderr.toString('utf8'),
            };

            if (code !== 0) {
                reject(
                    new Error(
                        `${label} failed (${signal ?? `exit ${code}`}): ${(result.stderr || result.stdout).trim()}`,
                    ),
                );

                return;
            }

            resolve(result);
        });
    });
}

function safeWindowsEnvironment(additions = {}, extraInheritedNames = []) {
    const inheritedNames = new Set([
        ...SAFE_WINDOWS_ENVIRONMENT_NAMES,
        ...extraInheritedNames.map((name) => name.toLocaleUpperCase('en-US')),
    ]);
    const safe = Object.fromEntries(
        Object.entries(process.env).filter(([name]) =>
            inheritedNames.has(name.toLocaleUpperCase('en-US')),
        ),
    );

    return { ...safe, ...additions };
}

export async function probePhpRuntime(runtimeRoot, expectedReview) {
    const phpExecutable = path.join(runtimeRoot, 'php.exe');
    await assertRegularFile(phpExecutable, 'supplied php.exe');
    await assertPeExecutable(phpExecutable, 'supplied php.exe');
    const phpCode = [
        '$extensions = array_map("strtolower", get_loaded_extensions());',
        'sort($extensions, SORT_STRING);',
        'echo json_encode([',
        '"version" => PHP_VERSION,',
        '"version_id" => PHP_VERSION_ID,',
        '"os_family" => PHP_OS_FAMILY,',
        '"sapi" => PHP_SAPI,',
        '"architecture_bits" => PHP_INT_SIZE * 8,',
        '"extensions" => $extensions,',
        '"loaded_ini" => php_ini_loaded_file() ?: "",',
        '"scanned_ini" => php_ini_scanned_files() ?: "",',
        '], JSON_THROW_ON_ERROR);',
    ].join(' ');
    const result = await runCommand(phpExecutable, ['-r', phpCode], {
        cwd: runtimeRoot,
        env: safeWindowsEnvironment(),
        label: 'PHP runtime probe',
        timeoutMs: 20_000,
    });
    let probe;

    try {
        probe = JSON.parse(result.stdout.trim());
    } catch (error) {
        fail(`PHP runtime probe returned invalid JSON: ${error.message}`);
    }

    validatePhpProbe(probe, expectedReview);
    assertIniContained(runtimeRoot, probe);

    return probe;
}

async function assertPeExecutable(target, label) {
    const handle = await open(target, 'r');

    try {
        const magic = Buffer.alloc(2);
        const { bytesRead } = await handle.read(magic, 0, 2, 0);

        if (bytesRead !== 2 || magic.toString('ascii') !== 'MZ') {
            fail(`${label} is not a Windows PE executable`);
        }
    } finally {
        await handle.close();
    }
}

export async function createPhpReviewManifest(runtimeRoot) {
    await assertPlainDirectory(runtimeRoot, 'PHP runtime');
    const inventory = await inspectTree(runtimeRoot);
    const probe = await probePhpRuntime(runtimeRoot);

    return {
        schema_version: RESOURCE_SCHEMA_VERSION,
        product: PHP_REVIEW_PRODUCT,
        version: probe.version,
        architecture: 'x64',
        extensions: probe.extensions,
        directories: inventory.directories,
        files: inventory.files,
    };
}

async function copyExactInventory(source, destination, inventory) {
    await mkdir(destination, { recursive: false });

    for (const relative of inventory.directories) {
        await mkdir(path.join(destination, ...relative.split('/')), {
            recursive: false,
        });
    }

    for (const relative of Object.keys(inventory.files)) {
        const destinationFile = path.join(destination, ...relative.split('/'));
        await copyFile(
            path.join(source, ...relative.split('/')),
            destinationFile,
            fsConstants.COPYFILE_EXCL,
        );
    }
}

async function stagePhpRuntime(
    runtimeRoot,
    reviewPath,
    reviewSha256,
    destination,
) {
    assertLowerSha256(reviewSha256, '--php-review-sha256');
    await assertRegularFile(reviewPath, 'PHP review manifest');

    if ((await sha256File(reviewPath)) !== reviewSha256) {
        fail('PHP review manifest does not match --php-review-sha256');
    }

    const review = validatePhpReviewManifest(
        await readJson(reviewPath, 'PHP review manifest'),
    );
    await validateManifestInventory(
        runtimeRoot,
        review,
        null,
        'PHP review manifest',
    );
    await probePhpRuntime(runtimeRoot, review);
    await copyExactInventory(runtimeRoot, destination, review);
    await probePhpRuntime(destination, review);
    const packagedManifest = {
        schema_version: RESOURCE_SCHEMA_VERSION,
        product: PHP_REVIEW_PRODUCT,
        version: review.version,
        architecture: review.architecture,
        extensions: review.extensions,
        required_extensions: [...REQUIRED_PHP_EXTENSIONS],
        review_manifest_sha256: reviewSha256,
        directories: review.directories,
        files: review.files,
    };
    await writeJson(path.join(destination, PHP_MANIFEST), packagedManifest);
}

function validateCloudflaredVersion(version) {
    const parts = typeof version === 'string' ? version.split('.') : [];

    if (
        ![3, 4].includes(parts.length) ||
        parts.some((part) => !/^\d+$/.test(part)) ||
        Number(parts[0]) < 2025 ||
        Number(parts[0]) > 2100 ||
        Number(parts[1]) < 1 ||
        Number(parts[1]) > 12
    ) {
        fail(`cloudflared reported an unsupported version: ${version}`);
    }
}

async function stageCloudflared(executable, expectedSha256, destination) {
    assertLowerSha256(expectedSha256, '--cloudflared-sha256');
    await assertRegularFile(executable, 'cloudflared.exe');
    await assertPeExecutable(executable, 'cloudflared.exe');
    const actualSha256 = await sha256File(executable);

    if (actualSha256 !== expectedSha256) {
        fail('supplied cloudflared.exe does not match --cloudflared-sha256');
    }

    const versionResult = await runCommand(executable, ['--version'], {
        cwd: path.dirname(executable),
        env: safeWindowsEnvironment(),
        label: 'cloudflared version probe',
        timeoutMs: 15_000,
    });
    const match = `${versionResult.stdout}\n${versionResult.stderr}`.match(
        /cloudflared version\s+(\d+\.\d+\.\d+(?:\.\d+)?)/i,
    );

    if (!match) {
        fail(
            'cloudflared --version did not return the expected official version format',
        );
    }

    validateCloudflaredVersion(match[1]);
    await mkdir(destination, { recursive: false });
    await copyFile(
        executable,
        path.join(destination, 'cloudflared.exe'),
        fsConstants.COPYFILE_EXCL,
    );
    await writeJson(path.join(destination, 'cloudflared.manifest.json'), {
        schema_version: RESOURCE_SCHEMA_VERSION,
        version: match[1],
        sha256: actualSha256,
    });
}

function shouldCopyPhp(relative) {
    return relative.toLocaleLowerCase('en-US').endsWith('.php');
}

function shouldCopyLanguage(relative) {
    return /\.(?:php|json)$/i.test(relative);
}

function shouldCopyJson(relative) {
    return relative.toLocaleLowerCase('en-US').endsWith('.json');
}

async function copySelectedTree(source, destination, predicate, label) {
    await assertPlainDirectory(source, label);
    const sourceInventory = await inspectTree(source);
    const selectedFiles = Object.keys(sourceInventory.files).filter(
        (relative) =>
            predicate(relative) && !isForbiddenReleaseSourcePath(relative),
    );

    if (selectedFiles.length === 0) {
        fail(`${label} has no allowlisted production files`);
    }

    const selectedDirectories = new Set();

    for (const relative of selectedFiles) {
        const parts = relative.split('/');
        parts.pop();

        while (parts.length > 0) {
            selectedDirectories.add(parts.join('/'));
            parts.pop();
        }
    }

    await mkdir(destination, { recursive: false });

    for (const relative of [...selectedDirectories].sort(
        (left, right) =>
            left.split('/').length - right.split('/').length ||
            left.localeCompare(right),
    )) {
        await mkdir(path.join(destination, ...relative.split('/')), {
            recursive: false,
        });
    }

    for (const relative of selectedFiles.sort()) {
        await copyFile(
            path.join(source, ...relative.split('/')),
            path.join(destination, ...relative.split('/')),
            fsConstants.COPYFILE_EXCL,
        );
    }
}

async function copyRequiredFile(sourceRoot, destinationRoot, relative) {
    const normalized = normalizeReleasePath(relative);
    const source = path.join(sourceRoot, ...normalized.split('/'));
    await assertRegularFile(source, `required source file ${normalized}`);
    const destination = path.join(destinationRoot, ...normalized.split('/'));
    await mkdir(path.dirname(destination), { recursive: true });
    await copyFile(source, destination, fsConstants.COPYFILE_EXCL);
}

async function assertCleanViteEnvironment(sourceRoot) {
    const rootEntries = await readdir(sourceRoot, { withFileTypes: true });
    const dotenv = rootEntries
        .filter(
            (entry) =>
                DOTENV_NAME.test(entry.name) && entry.name !== '.env.example',
        )
        .map((entry) => entry.name);

    if (dotenv.length > 0) {
        fail(
            `Vite release build refuses dotenv inputs; isolate the controlled build checkout first: ${dotenv.join(', ')}`,
        );
    }

    if (await pathExists(path.join(sourceRoot, 'public', 'hot'))) {
        fail('Vite release build refuses public/hot');
    }
}

async function buildAndValidateVite(sourceRoot) {
    await assertCleanViteEnvironment(sourceRoot);
    const vitePackage = path.join(
        sourceRoot,
        'node_modules',
        'vite',
        'package.json',
    );
    await assertRegularFile(
        vitePackage,
        'local pinned Vite package (run npm ci first)',
    );
    await assertRegularFile(
        path.join(sourceRoot, 'package-lock.json'),
        'package-lock.json',
    );
    const releaseBuildHelper = path.join(
        sourceRoot,
        'scripts',
        'desktop',
        'build-vite-release.mjs',
    );
    await assertRegularFile(releaseBuildHelper, 'Vite release build helper');
    await runCommand(process.execPath, [releaseBuildHelper, sourceRoot], {
        cwd: sourceRoot,
        env: safeWindowsEnvironment(
            {
                APP_ENV: 'production',
                NODE_ENV: 'production',
            },
            [
                'HTTPS_PROXY',
                'HTTP_PROXY',
                'NO_PROXY',
                'SSL_CERT_FILE',
                'SSL_CERT_DIR',
            ],
        ),
        label: 'Vite production build',
        timeoutMs: 300_000,
        maxOutputBytes: 16 * 1024 * 1024,
    });
    await validateViteOutput(path.join(sourceRoot, 'public', 'build'));
}

async function validateViteOutput(buildRoot) {
    const manifestPath = path.join(buildRoot, 'manifest.json');
    const manifest = await readJson(manifestPath, 'Vite manifest');

    if (
        manifest === null ||
        Array.isArray(manifest) ||
        typeof manifest !== 'object' ||
        Object.keys(manifest).length === 0
    ) {
        fail('Vite manifest must contain production entries');
    }

    const inventory = await inspectTree(buildRoot);
    const names = new Set(Object.keys(inventory.files));

    for (const relative of names) {
        if (
            isForbiddenReleaseSourcePath(relative) ||
            relative.toLocaleLowerCase('en-US') === 'hot'
        ) {
            fail(
                `Vite output contains a forbidden development artifact: ${relative}`,
            );
        }
    }

    const referenced = new Set();

    for (const value of Object.values(manifest)) {
        if (
            value === null ||
            Array.isArray(value) ||
            typeof value !== 'object'
        ) {
            fail('Vite manifest contains an invalid entry');
        }

        for (const key of ['file', 'css', 'assets']) {
            const paths =
                key === 'file'
                    ? [value[key]].filter(Boolean)
                    : (value[key] ?? []);

            if (
                !Array.isArray(paths) ||
                paths.some((item) => typeof item !== 'string')
            ) {
                fail(`Vite manifest entry has invalid ${key}`);
            }

            for (const item of paths) {
                const normalized = normalizeReleasePath(item);

                if (!names.has(normalized)) {
                    fail(
                        `Vite manifest references a missing asset: ${normalized}`,
                    );
                }

                referenced.add(normalized);
            }
        }
    }

    if (referenced.size === 0) {
        fail('Vite manifest does not reference any production assets');
    }
}

async function stageLaravelSource(sourceRoot, destination) {
    await mkdir(destination, { recursive: false });

    for (const relative of ['artisan', 'composer.json', 'composer.lock']) {
        await copyRequiredFile(sourceRoot, destination, relative);
    }

    await copyRequiredFile(sourceRoot, destination, 'bootstrap/app.php');
    await copyRequiredFile(sourceRoot, destination, 'bootstrap/providers.php');
    await mkdir(path.join(destination, 'bootstrap', 'cache'), {
        recursive: true,
    });

    await copySelectedTree(
        path.join(sourceRoot, 'app'),
        path.join(destination, 'app'),
        shouldCopyPhp,
        'app',
    );
    await copySelectedTree(
        path.join(sourceRoot, 'config'),
        path.join(destination, 'config'),
        shouldCopyPhp,
        'config',
    );

    if (await pathExists(path.join(sourceRoot, 'lang'))) {
        await copySelectedTree(
            path.join(sourceRoot, 'lang'),
            path.join(destination, 'lang'),
            shouldCopyLanguage,
            'lang',
        );
    }

    await copySelectedTree(
        path.join(sourceRoot, 'routes'),
        path.join(destination, 'routes'),
        shouldCopyPhp,
        'routes',
    );
    await mkdir(path.join(destination, 'database'), { recursive: false });
    await copySelectedTree(
        path.join(sourceRoot, 'database', 'migrations'),
        path.join(destination, 'database', 'migrations'),
        shouldCopyPhp,
        'database/migrations',
    );
    await copySelectedTree(
        path.join(sourceRoot, 'database', 'seeders'),
        path.join(destination, 'database', 'seeders'),
        shouldCopyPhp,
        'database/seeders',
    );
    await copySelectedTree(
        path.join(sourceRoot, 'database', 'data'),
        path.join(destination, 'database', 'data'),
        shouldCopyJson,
        'database/data',
    );
    await mkdir(path.join(destination, 'resources'), { recursive: false });
    await copySelectedTree(
        path.join(sourceRoot, 'resources', 'views'),
        path.join(destination, 'resources', 'views'),
        shouldCopyPhp,
        'resources/views',
    );

    await mkdir(path.join(destination, 'public'), { recursive: false });
}

async function stageLaravelPublicAssets(sourceRoot, destination) {
    for (const relative of [
        'public/.htaccess',
        'public/index.php',
        'public/favicon.ico',
        'public/favicon.svg',
        'public/apple-touch-icon.png',
        'public/robots.txt',
    ]) {
        if (await pathExists(path.join(sourceRoot, ...relative.split('/')))) {
            await copyRequiredFile(sourceRoot, destination, relative);
        }
    }

    await copySelectedTree(
        path.join(sourceRoot, 'public', 'build'),
        path.join(destination, 'public', 'build'),
        () => true,
        'public/build',
    );

    for (const directory of ['css', 'js', 'fonts']) {
        const source = path.join(sourceRoot, 'public', directory);

        if (await pathExists(source)) {
            await copySelectedTree(
                source,
                path.join(destination, 'public', directory),
                () => true,
                `public/${directory}`,
            );
        }
    }
}

async function verifyCommittedWayfinder(
    phpExecutable,
    laravelRoot,
    sourceRoot,
    workRoot,
) {
    const generatedRoot = path.join(workRoot, 'wayfinder-generated');
    const storageRoot = path.join(workRoot, 'wayfinder-storage');
    const databasePath = path.join(workRoot, 'wayfinder.sqlite');
    await mkdir(generatedRoot, { recursive: false });
    const databaseHandle = await open(databasePath, 'wx');
    await databaseHandle.close();

    for (const relative of [
        'app/private',
        'framework/cache',
        'framework/sessions',
        'framework/views',
        'logs',
    ]) {
        await mkdir(path.join(storageRoot, ...relative.split('/')), {
            recursive: true,
        });
    }

    await runCommand(
        phpExecutable,
        [
            'artisan',
            'wayfinder:generate',
            `--path=${generatedRoot}`,
            '--with-form',
            '--no-interaction',
        ],
        {
            cwd: laravelRoot,
            env: databaseEnvironment(databasePath, storageRoot),
            label: 'isolated Wayfinder route generation',
            timeoutMs: 120_000,
            maxOutputBytes: 8 * 1024 * 1024,
        },
    );

    for (const directory of ['actions', 'routes', 'wayfinder']) {
        await compareGeneratedTextTree(
            path.join(sourceRoot, 'resources', 'js', directory),
            path.join(generatedRoot, directory),
            `resources/js/${directory}`,
        );
    }
}

async function compareGeneratedTextTree(committedRoot, generatedRoot, label) {
    const committed = await inspectTree(committedRoot);
    const generated = await inspectTree(generatedRoot);

    if (
        JSON.stringify(committed.directories) !==
            JSON.stringify(generated.directories) ||
        JSON.stringify(Object.keys(committed.files)) !==
            JSON.stringify(Object.keys(generated.files))
    ) {
        fail(`${label} is stale or contains unexpected generated files`);
    }

    for (const relative of Object.keys(committed.files)) {
        if (isForbiddenReleaseSourcePath(relative)) {
            fail(
                `${label} contains a forbidden development artifact: ${relative}`,
            );
        }

        const committedText = await readFile(
            path.join(committedRoot, ...relative.split('/')),
            'utf8',
        );
        const generatedText = await readFile(
            path.join(generatedRoot, ...relative.split('/')),
            'utf8',
        );

        if (
            committedText.replaceAll('\r\n', '\n') !==
            generatedText.replaceAll('\r\n', '\n')
        ) {
            fail(
                `${label}/${relative} is stale; regenerate Wayfinder outputs before staging`,
            );
        }
    }
}

async function installComposerDependencies(
    phpExecutable,
    composerPhar,
    composerSha256,
    laravelRoot,
) {
    assertLowerSha256(composerSha256, '--composer-sha256');
    await assertRegularFile(composerPhar, 'Composer PHAR');

    if ((await sha256File(composerPhar)) !== composerSha256) {
        fail('Composer PHAR does not match --composer-sha256');
    }

    const base = [composerPhar, '--no-plugins'];
    const composerHome = path.join(path.dirname(laravelRoot), '.composer-home');
    const composerCache = path.join(
        path.dirname(laravelRoot),
        '.composer-cache',
    );
    const common = {
        cwd: laravelRoot,
        env: safeWindowsEnvironment(
            {
                APP_ENV: 'production',
                COMPOSER_ALLOW_SUPERUSER: '0',
                COMPOSER_CACHE_DIR: composerCache,
                COMPOSER_HOME: composerHome,
                COMPOSER_NO_INTERACTION: '1',
            },
            [
                'COMPOSER_AUTH',
                'HTTPS_PROXY',
                'HTTP_PROXY',
                'NO_PROXY',
                'SSL_CERT_FILE',
                'SSL_CERT_DIR',
            ],
        ),
        timeoutMs: 600_000,
        maxOutputBytes: 32 * 1024 * 1024,
    };

    try {
        await runCommand(
            phpExecutable,
            [
                ...base,
                'validate',
                '--strict',
                '--no-check-publish',
                '--no-interaction',
            ],
            {
                ...common,
                label: 'Composer lock validation',
            },
        );
        await runCommand(
            phpExecutable,
            [
                ...base,
                'install',
                '--no-dev',
                '--no-interaction',
                '--no-progress',
                '--prefer-dist',
                '--optimize-autoloader',
                '--classmap-authoritative',
                '--no-scripts',
            ],
            { ...common, label: 'Composer production install' },
        );
        await runCommand(
            phpExecutable,
            [...base, 'check-platform-reqs', '--no-dev'],
            {
                ...common,
                label: 'Composer production platform check',
            },
        );
        await verifyComposerProductionSet(laravelRoot);
    } finally {
        await rm(composerHome, { recursive: true, force: true });
        await rm(composerCache, { recursive: true, force: true });
    }
}

async function verifyComposerProductionSet(laravelRoot) {
    const lock = await readJson(
        path.join(laravelRoot, 'composer.lock'),
        'composer.lock',
    );
    const installed = await readJson(
        path.join(laravelRoot, 'vendor', 'composer', 'installed.json'),
        'Composer installed package list',
    );
    const installedPackages = Array.isArray(installed)
        ? installed
        : installed.packages;

    if (!Array.isArray(installedPackages)) {
        fail('Composer installed package list has an unsupported structure');
    }

    const devNames = new Set(
        (lock['packages-dev'] ?? []).map((item) => item.name),
    );
    const leaked = installedPackages
        .map((item) => item.name)
        .filter((name) => devNames.has(name));

    if (leaked.length > 0) {
        fail(
            `Composer production vendor contains development packages: ${leaked.join(', ')}`,
        );
    }

    for (const binary of ['phpunit', 'pest', 'pint', 'phpstan']) {
        for (const suffix of ['', '.bat']) {
            if (
                await pathExists(
                    path.join(
                        laravelRoot,
                        'vendor',
                        'bin',
                        `${binary}${suffix}`,
                    ),
                )
            ) {
                fail(
                    `Composer production vendor contains a development binary: vendor/bin/${binary}${suffix}`,
                );
            }
        }
    }

    await inspectTree(path.join(laravelRoot, 'vendor'));
}

function databaseEnvironment(databasePath, storageRoot) {
    const framework = path.join(storageRoot, 'framework');

    return safeWindowsEnvironment({
        APP_NAME: 'MediSmart',
        APP_URL: 'http://127.0.0.1',
        APP_ENV: 'production',
        APP_DEBUG: 'false',
        APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
        APP_SERVICES_CACHE: path.join(framework, 'cache', 'services.php'),
        APP_PACKAGES_CACHE: path.join(framework, 'cache', 'packages.php'),
        APP_CONFIG_CACHE: path.join(framework, 'cache', 'config.php'),
        APP_ROUTES_CACHE: path.join(framework, 'cache', 'routes.php'),
        APP_EVENTS_CACHE: path.join(framework, 'cache', 'events.php'),
        CACHE_STORE: 'array',
        CLINIC_ADDRESS: '',
        CLINIC_EMAIL: '',
        CLINIC_NAME: 'MediSmart Clinic',
        CLINIC_PHONE: '',
        CLINIC_TIMEZONE: 'Africa/Algiers',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: databasePath,
        DB_FOREIGN_KEYS: 'true',
        DB_JOURNAL_MODE: 'DELETE',
        DB_SYNCHRONOUS: 'FULL',
        FILESYSTEM_DISK: 'local',
        LARAVEL_STORAGE_PATH: storageRoot,
        LOG_CHANNEL: 'stderr',
        MEDISMART_SEED_DEMO_USER: 'false',
        QUEUE_CONNECTION: 'sync',
        SESSION_DRIVER: 'array',
        TELESCOPE_ENABLED: 'false',
        VIEW_COMPILED_PATH: path.join(framework, 'views'),
    });
}

async function createFreshDatabase(
    phpExecutable,
    laravelRoot,
    initialRoot,
    workRoot,
) {
    await mkdir(initialRoot, { recursive: false });
    await mkdir(path.join(initialRoot, 'storage'), { recursive: false });
    const databasePath = path.join(initialRoot, 'database.sqlite');
    const handle = await open(databasePath, 'wx');
    await handle.close();
    const storageRoot = path.join(workRoot, 'laravel-storage');

    for (const relative of [
        'app/private',
        'framework/cache',
        'framework/sessions',
        'framework/testing',
        'framework/views',
        'logs',
    ]) {
        await mkdir(path.join(storageRoot, ...relative.split('/')), {
            recursive: true,
        });
    }

    const environment = databaseEnvironment(databasePath, storageRoot);
    await runCommand(
        phpExecutable,
        ['artisan', 'migrate', '--seed', '--force', '--no-interaction'],
        {
            cwd: laravelRoot,
            env: environment,
            label: 'fresh production database migration and seed',
            timeoutMs: 300_000,
            maxOutputBytes: 16 * 1024 * 1024,
        },
    );
    const inspection = await inspectSqliteDatabase(phpExecutable, databasePath);
    const expectedMigrationCount = Object.keys(
        (await inspectTree(path.join(laravelRoot, 'database', 'migrations')))
            .files,
    ).length;
    const summary = validateSeedInspection(inspection, expectedMigrationCount);

    for (const suffix of ['-wal', '-shm', '-journal']) {
        if (await pathExists(`${databasePath}${suffix}`)) {
            fail(
                `fresh SQLite template retained a forbidden sidecar: database.sqlite${suffix}`,
            );
        }
    }

    const databaseStat = await stat(databasePath);
    await writeJson(path.join(initialRoot, DATABASE_MANIFEST), {
        schema_version: RESOURCE_SCHEMA_VERSION,
        sha256: await sha256File(databasePath),
        size: databaseStat.size,
        migration_count: summary.migrationCount,
        reference_seed_counts: summary.referenceSeedCounts,
        empty_table_count: summary.emptyTables.length,
    });
}

async function inspectSqliteDatabase(phpExecutable, databasePath) {
    const phpCode = [
        '$db = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);',
        '$integrity = $db->query("PRAGMA integrity_check")->fetchColumn();',
        '$journal = strtolower((string) $db->query("PRAGMA journal_mode")->fetchColumn());',
        "$names = $db->query(\"SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name\")->fetchAll(PDO::FETCH_COLUMN);",
        '$counts = [];',
        'foreach ($names as $name) { $quoted = "\\\"" . str_replace("\\\"", "\\\"\\\"", $name) . "\\\""; $counts[$name] = (int) $db->query("SELECT COUNT(*) FROM " . $quoted)->fetchColumn(); }',
        "$sensitiveConfigurationRows = isset($counts[\"cabinet_settings\"]) ? (int) $db->query(\"SELECT COUNT(*) FROM cabinet_settings WHERE COALESCE(TRIM(address), '') <> '' OR COALESCE(TRIM(city), '') <> '' OR COALESCE(TRIM(phone), '') <> '' OR COALESCE(TRIM(email), '') <> '' OR COALESCE(TRIM(logo_path), '') <> '' OR COALESCE(TRIM(receipt_footer), '') <> '' OR COALESCE(TRIM(prescription_footer), '') <> ''\")->fetchColumn() : -1;",
        'echo json_encode(["integrity" => $integrity, "journal_mode" => $journal, "counts" => $counts, "sensitive_configuration_rows" => $sensitiveConfigurationRows], JSON_THROW_ON_ERROR);',
    ].join(' ');
    const result = await runCommand(
        phpExecutable,
        ['-r', phpCode, databasePath],
        {
            cwd: path.dirname(databasePath),
            label: 'SQLite template inspection',
            timeoutMs: 30_000,
        },
    );

    try {
        return JSON.parse(result.stdout.trim());
    } catch (error) {
        fail(
            `SQLite template inspection returned invalid JSON: ${error.message}`,
        );
    }
}

export function validateSeedInspection(inspection, expectedMigrationCount) {
    assertExactKeys(
        inspection,
        ['integrity', 'journal_mode', 'counts', 'sensitive_configuration_rows'],
        'SQLite inspection',
    );

    if (inspection.integrity !== 'ok' || inspection.journal_mode !== 'delete') {
        fail(
            'SQLite template must pass integrity_check and use DELETE journal mode',
        );
    }

    if (
        inspection.counts === null ||
        Array.isArray(inspection.counts) ||
        typeof inspection.counts !== 'object'
    ) {
        fail('SQLite inspection counts must be an object');
    }

    for (const [table, count] of Object.entries(inspection.counts)) {
        if (!Number.isSafeInteger(count) || count < 0) {
            fail(`SQLite inspection returned an invalid count for ${table}`);
        }

        if (
            table !== 'migrations' &&
            !ALLOWED_NONEMPTY_DATABASE_TABLES.has(table) &&
            count !== 0
        ) {
            fail(
                `fresh SQLite template contains user, clinical, credential, or runtime data in ${table}`,
            );
        }
    }

    if (
        inspection.counts.cabinet_settings !== 1 ||
        inspection.sensitive_configuration_rows !== 0
    ) {
        fail(
            'fresh SQLite template must contain exactly one generic cabinet row with no clinic identity or document branding',
        );
    }

    for (const table of REQUIRED_EMPTY_DATABASE_TABLES) {
        if (inspection.counts[table] !== 0) {
            fail(
                `fresh SQLite template is missing an empty required table or contains data in ${table}`,
            );
        }
    }

    if (inspection.counts.migrations !== expectedMigrationCount) {
        fail(
            `fresh SQLite template has ${inspection.counts.migrations ?? 0} migrations; expected ${expectedMigrationCount}`,
        );
    }

    const referenceSeedCounts = {};

    for (const table of REQUIRED_REFERENCE_TABLES) {
        const count = inspection.counts[table];

        if (!Number.isSafeInteger(count) || count <= 0) {
            fail(
                `fresh SQLite template is missing required reference seed data in ${table}`,
            );
        }

        referenceSeedCounts[table] = count;
    }

    const emptyTables = Object.entries(inspection.counts)
        .filter(([, count]) => count === 0)
        .map(([table]) => table)
        .sort();

    return {
        migrationCount: inspection.counts.migrations,
        referenceSeedCounts: sortObject(referenceSeedCounts),
        emptyTables,
    };
}

async function assertNoLaravelContamination(laravelRoot) {
    const inventory = await inspectTree(laravelRoot, {
        exclude: new Set([LARAVEL_MANIFEST]),
    });

    for (const relative of Object.keys(inventory.files)) {
        const lower = relative.toLocaleLowerCase('en-US');
        const name = lower.split('/').at(-1);

        if (
            DOTENV_NAME.test(name) ||
            name === 'hot' ||
            /(?:\.sqlite(?:-(?:wal|shm|journal))?|\.log)$/i.test(name) ||
            (!lower.startsWith('vendor/') &&
                isForbiddenReleaseSourcePath(relative)) ||
            lower.startsWith('node_modules/') ||
            lower.startsWith('tests/') ||
            lower.startsWith('storage/') ||
            lower.includes('/uploads/') ||
            lower.includes('/restore-work/') ||
            lower.includes('/recovery') ||
            /(?:^|\/)(?:id_rsa|id_ed25519|credentials\.json|origin-cert\.pem)$/i.test(
                relative,
            )
        ) {
            fail(
                `Laravel release contains a forbidden mutable, secret, or development artifact: ${relative}`,
            );
        }

        if (!isAllowedLaravelFile(relative)) {
            fail(
                `Laravel release contains a file outside the production allowlist: ${relative}`,
            );
        }
    }

    for (const relative of inventory.directories) {
        if (!isAllowedLaravelDirectory(relative)) {
            fail(
                `Laravel release contains a directory outside the production allowlist: ${relative}`,
            );
        }
    }

    for (const required of REQUIRED_LARAVEL_FILES) {
        if (!Object.hasOwn(inventory.files, required)) {
            fail(
                `Laravel release is missing required runtime file: ${required}`,
            );
        }
    }

    await validateViteOutput(path.join(laravelRoot, 'public', 'build'));

    return inventory;
}

function isUnder(relative, root) {
    return relative === root || relative.startsWith(`${root}/`);
}

function isAllowedLaravelFile(relative) {
    if (['artisan', 'composer.json', 'composer.lock'].includes(relative)) {
        return true;
    }

    if (['bootstrap/app.php', 'bootstrap/providers.php'].includes(relative)) {
        return true;
    }

    if (isUnder(relative, 'vendor')) {
        return true;
    }

    if (
        isUnder(relative, 'app') ||
        isUnder(relative, 'config') ||
        isUnder(relative, 'routes')
    ) {
        return relative.toLocaleLowerCase('en-US').endsWith('.php');
    }

    if (isUnder(relative, 'lang')) {
        return /\.(?:json|php)$/i.test(relative);
    }

    if (
        isUnder(relative, 'database/migrations') ||
        isUnder(relative, 'database/seeders')
    ) {
        return relative.toLocaleLowerCase('en-US').endsWith('.php');
    }

    if (isUnder(relative, 'database/data')) {
        return relative.toLocaleLowerCase('en-US').endsWith('.json');
    }

    if (isUnder(relative, 'resources/views')) {
        return relative.toLocaleLowerCase('en-US').endsWith('.php');
    }

    if (
        [
            'public/.htaccess',
            'public/index.php',
            'public/favicon.ico',
            'public/favicon.svg',
            'public/apple-touch-icon.png',
            'public/robots.txt',
        ].includes(relative)
    ) {
        return true;
    }

    return ['public/build', 'public/css', 'public/js', 'public/fonts'].some(
        (root) => isUnder(relative, root),
    );
}

function isAllowedLaravelDirectory(relative) {
    if (
        ['app', 'config', 'lang', 'routes', 'vendor'].some((root) =>
            isUnder(relative, root),
        )
    ) {
        return true;
    }

    if (relative === 'bootstrap' || relative === 'bootstrap/cache') {
        return true;
    }

    if (relative === 'database') {
        return true;
    }

    if (
        ['database/migrations', 'database/seeders', 'database/data'].some(
            (root) => isUnder(relative, root),
        )
    ) {
        return true;
    }

    if (relative === 'resources' || isUnder(relative, 'resources/views')) {
        return true;
    }

    if (relative === 'public') {
        return true;
    }

    return ['public/build', 'public/css', 'public/js', 'public/fonts'].some(
        (root) => isUnder(relative, root),
    );
}

async function writeLaravelManifest(laravelRoot) {
    const inventory = await assertNoLaravelContamination(laravelRoot);
    await writeJson(path.join(laravelRoot, LARAVEL_MANIFEST), {
        schema_version: RESOURCE_SCHEMA_VERSION,
        composer_lock_sha256: await sha256File(
            path.join(laravelRoot, 'composer.lock'),
        ),
        vite_manifest_sha256: await sha256File(
            path.join(laravelRoot, 'public', 'build', 'manifest.json'),
        ),
        directories: inventory.directories,
        files: inventory.files,
    });
}

async function validateLaravelManifest(laravelRoot) {
    const manifest = await readJson(
        path.join(laravelRoot, LARAVEL_MANIFEST),
        'Laravel release manifest',
    );
    assertExactKeys(
        manifest,
        [
            'schema_version',
            'composer_lock_sha256',
            'vite_manifest_sha256',
            'directories',
            'files',
        ],
        'Laravel release manifest',
    );

    if (manifest.schema_version !== RESOURCE_SCHEMA_VERSION) {
        fail('Laravel release manifest has an unsupported schema');
    }

    assertLowerSha256(
        manifest.composer_lock_sha256,
        'Laravel composer lock hash',
    );
    assertLowerSha256(
        manifest.vite_manifest_sha256,
        'Laravel Vite manifest hash',
    );
    await validateManifestInventory(
        laravelRoot,
        manifest,
        LARAVEL_MANIFEST,
        'Laravel release manifest',
    );

    if (
        (await sha256File(path.join(laravelRoot, 'composer.lock'))) !==
        manifest.composer_lock_sha256
    ) {
        fail('Laravel composer.lock does not match the release manifest');
    }

    if (
        (await sha256File(
            path.join(laravelRoot, 'public', 'build', 'manifest.json'),
        )) !== manifest.vite_manifest_sha256
    ) {
        fail('Laravel Vite manifest does not match the release manifest');
    }

    await assertNoLaravelContamination(laravelRoot);
}

async function validatePackagedPhp(phpRoot) {
    const manifest = await readJson(
        path.join(phpRoot, PHP_MANIFEST),
        'packaged PHP manifest',
    );
    assertExactKeys(
        manifest,
        [
            'schema_version',
            'product',
            'version',
            'architecture',
            'extensions',
            'required_extensions',
            'review_manifest_sha256',
            'directories',
            'files',
        ],
        'packaged PHP manifest',
    );

    if (
        manifest.schema_version !== RESOURCE_SCHEMA_VERSION ||
        manifest.product !== PHP_REVIEW_PRODUCT ||
        manifest.architecture !== 'x64'
    ) {
        fail(
            'packaged PHP manifest has an unsupported schema, product, or architecture',
        );
    }

    validatePhpVersion(manifest.version);
    validatePhpExtensions(manifest.extensions);

    if (
        JSON.stringify(manifest.required_extensions) !==
        JSON.stringify(REQUIRED_PHP_EXTENSIONS)
    ) {
        fail(
            'packaged PHP manifest does not use the current required extension baseline',
        );
    }

    assertLowerSha256(
        manifest.review_manifest_sha256,
        'packaged PHP review hash',
    );
    await validateManifestInventory(
        phpRoot,
        manifest,
        PHP_MANIFEST,
        'packaged PHP manifest',
    );
}

async function validateCloudflared(cloudflaredRoot) {
    const manifest = await readJson(
        path.join(cloudflaredRoot, 'cloudflared.manifest.json'),
        'cloudflared manifest',
    );
    assertExactKeys(
        manifest,
        ['schema_version', 'version', 'sha256'],
        'cloudflared manifest',
    );

    if (manifest.schema_version !== RESOURCE_SCHEMA_VERSION) {
        fail('cloudflared manifest has an unsupported schema');
    }

    validateCloudflaredVersion(manifest.version);
    assertLowerSha256(manifest.sha256, 'cloudflared hash');
    const entries = (await readdir(cloudflaredRoot)).sort();

    if (
        JSON.stringify(entries) !==
        JSON.stringify(['cloudflared.exe', 'cloudflared.manifest.json'])
    ) {
        fail(
            `cloudflared resource directory contains unexpected entries: ${entries.join(', ')}`,
        );
    }

    await assertRegularFile(
        path.join(cloudflaredRoot, 'cloudflared.exe'),
        'staged cloudflared.exe',
    );

    if (
        (await sha256File(path.join(cloudflaredRoot, 'cloudflared.exe'))) !==
        manifest.sha256
    ) {
        fail('staged cloudflared.exe does not match cloudflared.manifest.json');
    }
}

async function validateInitialDatabase(initialRoot) {
    const databasePath = path.join(initialRoot, 'database.sqlite');
    const manifest = await readJson(
        path.join(initialRoot, DATABASE_MANIFEST),
        'initial database manifest',
    );
    assertExactKeys(
        manifest,
        [
            'schema_version',
            'sha256',
            'size',
            'migration_count',
            'reference_seed_counts',
            'empty_table_count',
        ],
        'initial database manifest',
    );

    if (manifest.schema_version !== RESOURCE_SCHEMA_VERSION) {
        fail('initial database manifest has an unsupported schema');
    }

    assertLowerSha256(manifest.sha256, 'initial database hash');
    await assertRegularFile(databasePath, 'initial database.sqlite');
    const info = await stat(databasePath);

    if (
        info.size <= 0 ||
        info.size !== manifest.size ||
        (await sha256File(databasePath)) !== manifest.sha256
    ) {
        fail(
            'initial database.sqlite does not match its size and hash manifest',
        );
    }

    if (
        !Number.isSafeInteger(manifest.migration_count) ||
        manifest.migration_count <= 0 ||
        !Number.isSafeInteger(manifest.empty_table_count) ||
        manifest.empty_table_count <= 0
    ) {
        fail(
            'initial database manifest has invalid migration or empty-table counts',
        );
    }

    if (
        manifest.reference_seed_counts === null ||
        Array.isArray(manifest.reference_seed_counts) ||
        typeof manifest.reference_seed_counts !== 'object'
    ) {
        fail('initial database manifest has invalid reference seed counts');
    }

    for (const table of REQUIRED_REFERENCE_TABLES) {
        if (
            !Number.isSafeInteger(manifest.reference_seed_counts[table]) ||
            manifest.reference_seed_counts[table] <= 0
        ) {
            fail(
                `initial database manifest is missing reference seed count for ${table}`,
            );
        }
    }

    for (const suffix of ['-wal', '-shm', '-journal']) {
        if (await pathExists(`${databasePath}${suffix}`)) {
            fail(
                `initial database contains forbidden SQLite sidecar database.sqlite${suffix}`,
            );
        }
    }

    const entries = (await readdir(initialRoot, { withFileTypes: true }))
        .map((entry) => entry.name)
        .sort();

    if (
        JSON.stringify(entries) !==
        JSON.stringify(
            [
                DATABASE_MANIFEST,
                MIGRATION_CONTRACT,
                'database.sqlite',
                'storage',
            ].sort(),
        )
    ) {
        fail(
            `initial resource directory contains unexpected entries: ${entries.join(', ')}`,
        );
    }

    const storageRoot = path.join(initialRoot, 'storage');
    await assertPlainDirectory(storageRoot, 'initial storage directory');

    if ((await readdir(storageRoot)).length !== 0) {
        fail(
            'initial storage directory must be empty and contain no clinic files',
        );
    }
}

async function readApplicationVersion(sourceRoot) {
    const tauriConfiguration = await readJson(
        path.join(sourceRoot, 'src-tauri', 'tauri.conf.json'),
        'Tauri configuration',
    );

    if (
        tauriConfiguration === null ||
        Array.isArray(tauriConfiguration) ||
        typeof tauriConfiguration !== 'object'
    ) {
        fail('Tauri configuration must be a JSON object');
    }

    assertApplicationVersion(tauriConfiguration.version);

    return tauriConfiguration.version;
}

async function writeMigrationContract(resourcesRoot, applicationVersion) {
    const laravelManifest = await readJson(
        path.join(resourcesRoot, 'laravel', LARAVEL_MANIFEST),
        'Laravel release manifest',
    );
    const databaseManifest = await readJson(
        path.join(resourcesRoot, 'initial', DATABASE_MANIFEST),
        'initial database manifest',
    );
    const migrations = migrationEntriesFromLaravelFiles(laravelManifest.files);
    const contract = {
        schema_version: RESOURCE_SCHEMA_VERSION,
        application_version: applicationVersion,
        initial_database_sha256: databaseManifest.sha256,
        migration_helper: {
            path: MIGRATION_HELPER_PATH,
            sha256: laravelManifest.files[MIGRATION_HELPER_PATH],
        },
        migrations,
        migration_set_sha256: canonicalMigrationSetSha256(migrations),
    };

    validateMigrationContractData(contract, {
        applicationVersion,
        initialDatabaseSha256: databaseManifest.sha256,
        migrationCount: databaseManifest.migration_count,
        laravelFiles: laravelManifest.files,
    });
    await writeJson(
        path.join(resourcesRoot, 'initial', MIGRATION_CONTRACT),
        contract,
    );
}

async function validateMigrationContract(
    resourcesRoot,
    expectedApplicationVersion,
) {
    const contract = await readJson(
        path.join(resourcesRoot, 'initial', MIGRATION_CONTRACT),
        'migration contract',
    );
    const laravelManifest = await readJson(
        path.join(resourcesRoot, 'laravel', LARAVEL_MANIFEST),
        'Laravel release manifest',
    );
    const databaseManifest = await readJson(
        path.join(resourcesRoot, 'initial', DATABASE_MANIFEST),
        'initial database manifest',
    );

    return validateMigrationContractData(contract, {
        applicationVersion: expectedApplicationVersion,
        initialDatabaseSha256: databaseManifest.sha256,
        migrationCount: databaseManifest.migration_count,
        laravelFiles: laravelManifest.files,
    });
}

async function writeRootManifest(resourcesRoot) {
    const inventory = await inspectTree(resourcesRoot, {
        exclude: new Set([RESOURCE_MANIFEST]),
    });
    await writeJson(path.join(resourcesRoot, RESOURCE_MANIFEST), {
        schema_version: RESOURCE_SCHEMA_VERSION,
        directories: inventory.directories,
        files: inventory.files,
    });
}

export async function validateReleaseResources(
    resourcesRoot,
    { probeBinaries = false, expectedApplicationVersion = null } = {},
) {
    await assertSafeExistingPathChain(resourcesRoot);
    await assertPlainDirectory(resourcesRoot, 'release resources');
    const manifest = await readJson(
        path.join(resourcesRoot, RESOURCE_MANIFEST),
        'release resource manifest',
    );
    assertExactKeys(
        manifest,
        ['schema_version', 'directories', 'files'],
        'release resource manifest',
    );

    if (manifest.schema_version !== RESOURCE_SCHEMA_VERSION) {
        fail('release resource manifest has an unsupported schema');
    }

    await validateManifestInventory(
        resourcesRoot,
        manifest,
        RESOURCE_MANIFEST,
        'release resource manifest',
    );

    const topLevel = (await readdir(resourcesRoot, { withFileTypes: true }))
        .map((entry) => entry.name)
        .sort();
    const expectedTopLevel = [
        'README.md',
        RESOURCE_MANIFEST,
        'cloudflared',
        'initial',
        'laravel',
        'php',
    ].sort();

    if (JSON.stringify(topLevel) !== JSON.stringify(expectedTopLevel)) {
        fail(
            `release resources contain unexpected top-level entries: ${topLevel.join(', ')}`,
        );
    }

    await validateLaravelManifest(path.join(resourcesRoot, 'laravel'));
    await validatePackagedPhp(path.join(resourcesRoot, 'php'));
    await validateCloudflared(path.join(resourcesRoot, 'cloudflared'));
    await validateInitialDatabase(path.join(resourcesRoot, 'initial'));
    await validateMigrationContract(resourcesRoot, expectedApplicationVersion);

    if (probeBinaries) {
        assertWindowsHost();
        const phpManifest = await readJson(
            path.join(resourcesRoot, 'php', PHP_MANIFEST),
            'packaged PHP manifest',
        );
        await probePhpRuntime(path.join(resourcesRoot, 'php'), phpManifest);
        const databaseManifest = await readJson(
            path.join(resourcesRoot, 'initial', DATABASE_MANIFEST),
            'initial database manifest',
        );
        const databaseInspection = await inspectSqliteDatabase(
            path.join(resourcesRoot, 'php', 'php.exe'),
            path.join(resourcesRoot, 'initial', 'database.sqlite'),
        );
        validateSeedInspection(
            databaseInspection,
            databaseManifest.migration_count,
        );
    }

    return manifest;
}

export async function assertReplacePolicy(output, replace) {
    await assertSafeExistingPathChain(output);

    if (await pathExists(output)) {
        if (!replace) {
            fail(
                `release resource destination already exists; refusing replacement without --replace: ${output}`,
            );
        }

        await assertPlainDirectory(
            output,
            'existing release resource destination',
        );
    }
}

async function publishResources(stagingRoot, output, replace) {
    await assertReplacePolicy(output, replace);
    const parent = path.dirname(output);

    if (!(await pathExists(output))) {
        await rename(stagingRoot, output);

        return;
    }

    const backup = path.join(
        parent,
        `.${path.basename(output)}.previous-${randomBytes(8).toString('hex')}`,
    );
    await rename(output, backup);

    try {
        await rename(stagingRoot, output);
    } catch (error) {
        await rename(backup, output).catch(() => {});

        throw error;
    }

    await rm(backup, { recursive: true, force: false });
}

function assertNoOverlap(sourceRoot, output, inputs) {
    const source = path.resolve(sourceRoot);
    const target = path.resolve(output);

    if (source === target || isWithin(target, source)) {
        fail(
            'release resource destination must not equal or contain the source checkout',
        );
    }

    for (const input of inputs) {
        const resolved = path.resolve(input);

        if (resolved === target || isWithin(target, resolved)) {
            fail(`release input must not be inside the destination: ${input}`);
        }
    }
}

export async function stageReleaseResources({
    sourceRoot,
    output,
    phpRuntime,
    phpReview,
    phpReviewSha256,
    composerPhar,
    composerSha256,
    cloudflared,
    cloudflaredSha256,
    replace = false,
}) {
    assertWindowsHost();
    const absolute = Object.fromEntries(
        Object.entries({
            sourceRoot,
            output,
            phpRuntime,
            phpReview,
            composerPhar,
            cloudflared,
        }).map(([key, value]) => [key, path.resolve(value)]),
    );
    assertNoOverlap(absolute.sourceRoot, absolute.output, [
        absolute.phpRuntime,
        absolute.phpReview,
        absolute.composerPhar,
        absolute.cloudflared,
    ]);
    await assertPlainDirectory(absolute.sourceRoot, 'Laravel source checkout');

    for (const target of Object.values(absolute)) {
        await assertSafeExistingPathChain(target);
    }

    await assertReplacePolicy(absolute.output, replace);
    await assertCleanViteEnvironment(absolute.sourceRoot);
    const applicationVersion = await readApplicationVersion(
        absolute.sourceRoot,
    );

    const outputParent = path.dirname(absolute.output);
    await mkdir(outputParent, { recursive: true });
    await assertSafeExistingPathChain(outputParent);
    const stagingRoot = await mkdtemp(
        path.join(outputParent, `.${path.basename(absolute.output)}.stage-`),
    );
    const workRoot = await mkdtemp(
        path.join(outputParent, `.${path.basename(absolute.output)}.work-`),
    );
    let published = false;

    try {
        const sourceReadme = path.join(
            absolute.sourceRoot,
            'src-tauri',
            'resources',
            'README.md',
        );
        await assertRegularFile(sourceReadme, 'desktop resource README');
        await copyFile(
            sourceReadme,
            path.join(stagingRoot, 'README.md'),
            fsConstants.COPYFILE_EXCL,
        );

        await stagePhpRuntime(
            absolute.phpRuntime,
            absolute.phpReview,
            phpReviewSha256,
            path.join(stagingRoot, 'php'),
        );
        await stageCloudflared(
            absolute.cloudflared,
            cloudflaredSha256,
            path.join(stagingRoot, 'cloudflared'),
        );
        const laravelRoot = path.join(stagingRoot, 'laravel');
        await stageLaravelSource(absolute.sourceRoot, laravelRoot);
        await installComposerDependencies(
            path.join(stagingRoot, 'php', 'php.exe'),
            absolute.composerPhar,
            composerSha256,
            laravelRoot,
        );
        await verifyCommittedWayfinder(
            path.join(stagingRoot, 'php', 'php.exe'),
            laravelRoot,
            absolute.sourceRoot,
            workRoot,
        );
        await buildAndValidateVite(absolute.sourceRoot);
        await stageLaravelPublicAssets(absolute.sourceRoot, laravelRoot);
        await createFreshDatabase(
            path.join(stagingRoot, 'php', 'php.exe'),
            laravelRoot,
            path.join(stagingRoot, 'initial'),
            workRoot,
        );
        await writeLaravelManifest(laravelRoot);
        await writeMigrationContract(stagingRoot, applicationVersion);
        await writeRootManifest(stagingRoot);
        await validateReleaseResources(stagingRoot, {
            probeBinaries: true,
            expectedApplicationVersion: applicationVersion,
        });
        await publishResources(stagingRoot, absolute.output, replace);
        published = true;

        return absolute.output;
    } finally {
        await rm(workRoot, { recursive: true, force: true });

        if (!published) {
            await rm(stagingRoot, { recursive: true, force: true });
        }
    }
}

export async function writePhpReviewManifest(target, manifest) {
    await writeJson(target, manifest);

    return await sha256File(target);
}

export async function ensureWritableParent(target) {
    const parent = path.dirname(path.resolve(target));
    await assertSafeExistingPathChain(parent);
    await access(parent, fsConstants.W_OK);
}
