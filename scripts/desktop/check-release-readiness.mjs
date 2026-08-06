#!/usr/bin/env node

import { spawn } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    RESOURCE_MANIFEST,
    assertRegularFile,
    assertWindowsHost,
    parseCliArguments,
    requireCliArguments,
    sha256File,
    validateReleaseResources,
} from './release-resources.mjs';

const LOWER_SHA256 = /^[0-9a-f]{64}$/;
const CERTIFICATE_THUMBPRINT = /^[0-9A-F]{40,128}$/;
const CANONICAL_SEMVER =
    /^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/;
const UPDATER_TARGET = /^windows-(?:x86_64|aarch64)-(?:nsis|msi)$/;
const TAURI_SIGNATURE = /^[A-Za-z0-9+/]+={0,2}$/;
const MAX_EVIDENCE_AGE_MS = 30 * 24 * 60 * 60 * 1000;

function fail(message) {
    throw new Error(message);
}

function assertExactKeys(value, expected, label) {
    if (value === null || Array.isArray(value) || typeof value !== 'object') {
        fail(`${label} must be a JSON object`);
    }

    const actual = Object.keys(value).sort();
    const required = [...expected].sort();

    if (
        actual.length !== required.length ||
        actual.some((key, index) => key !== required[index])
    ) {
        fail(`${label} must contain exactly: ${required.join(', ')}`);
    }
}

function assertDigest(value, label) {
    if (typeof value !== 'string' || !LOWER_SHA256.test(value)) {
        fail(`${label} must be a lowercase SHA-256 digest`);
    }
}

export function validateUpdaterReleaseManifest(
    manifest,
    { applicationVersion, target, artifactUrl, signature },
) {
    assertExactKeys(
        manifest,
        ['version', 'notes', 'pub_date', 'platforms'],
        'updater manifest',
    );

    if (
        typeof applicationVersion !== 'string' ||
        !CANONICAL_SEMVER.test(applicationVersion) ||
        manifest.version !== applicationVersion
    ) {
        fail('updater manifest version does not match the release version');
    }

    if (typeof manifest.notes !== 'string' || manifest.notes.length > 20_000) {
        fail('updater manifest notes must be a bounded string');
    }

    if (
        typeof manifest.pub_date !== 'string' ||
        !Number.isFinite(Date.parse(manifest.pub_date))
    ) {
        fail('updater manifest pub_date must be an ISO-8601 timestamp');
    }

    if (typeof target !== 'string' || !UPDATER_TARGET.test(target)) {
        fail('updater target must identify an approved Windows bundle');
    }

    if (
        manifest.platforms === null ||
        Array.isArray(manifest.platforms) ||
        typeof manifest.platforms !== 'object'
    ) {
        fail('updater manifest platforms must be an object');
    }

    const platform = manifest.platforms[target];
    assertExactKeys(platform, ['url', 'signature'], `updater target ${target}`);

    if (typeof artifactUrl !== 'string' || platform.url !== artifactUrl) {
        fail('updater manifest URL does not match the approved artifact URL');
    }

    let parsedArtifactUrl;

    try {
        parsedArtifactUrl = new URL(artifactUrl);
    } catch {
        fail('updater artifact URL must be a valid HTTPS URL');
    }

    if (
        parsedArtifactUrl.protocol !== 'https:' ||
        parsedArtifactUrl.hostname === '' ||
        parsedArtifactUrl.username !== '' ||
        parsedArtifactUrl.password !== '' ||
        parsedArtifactUrl.port !== '' ||
        parsedArtifactUrl.search !== '' ||
        parsedArtifactUrl.hash !== '' ||
        !parsedArtifactUrl.pathname.toLowerCase().endsWith('.zip')
    ) {
        fail(
            'updater artifact URL must be a static HTTPS .zip URL without credentials, query, fragment, or non-default port',
        );
    }

    if (
        typeof signature !== 'string' ||
        signature.length < 40 ||
        signature.length > 4096 ||
        signature.length % 4 !== 0 ||
        !TAURI_SIGNATURE.test(signature)
    ) {
        fail('updater signature file is not a bounded Tauri base64 signature');
    }

    if (platform.signature !== signature) {
        fail(
            'updater manifest signature does not match the generated .sig file',
        );
    }
}

export function validateCleanVmEvidence(
    evidence,
    {
        applicationVersion,
        installerSha256,
        resourceManifestSha256,
        updaterArtifactSha256,
        updaterManifestSha256,
        updaterSignatureSha256,
        now = Date.now(),
    },
) {
    assertExactKeys(
        evidence,
        [
            'schema_version',
            'application_version',
            'installer_sha256',
            'resource_manifest_sha256',
            'updater_artifact_sha256',
            'updater_manifest_sha256',
            'updater_signature_sha256',
            'tested_at',
            'windows',
            'checks',
        ],
        'clean-VM evidence',
    );

    if (evidence.schema_version !== 2) {
        fail('clean-VM evidence has an unsupported schema');
    }

    if (evidence.application_version !== applicationVersion) {
        fail('clean-VM evidence application version does not match');
    }

    assertDigest(evidence.installer_sha256, 'clean-VM installer hash');
    assertDigest(
        evidence.resource_manifest_sha256,
        'clean-VM resource manifest hash',
    );
    assertDigest(
        evidence.updater_artifact_sha256,
        'clean-VM updater artifact hash',
    );
    assertDigest(
        evidence.updater_manifest_sha256,
        'clean-VM updater manifest hash',
    );
    assertDigest(
        evidence.updater_signature_sha256,
        'clean-VM updater signature hash',
    );

    if (evidence.installer_sha256 !== installerSha256) {
        fail('clean-VM evidence does not bind the inspected installer');
    }

    if (evidence.resource_manifest_sha256 !== resourceManifestSha256) {
        fail('clean-VM evidence does not bind the inspected resource manifest');
    }

    if (evidence.updater_artifact_sha256 !== updaterArtifactSha256) {
        fail('clean-VM evidence does not bind the inspected updater artifact');
    }

    if (evidence.updater_manifest_sha256 !== updaterManifestSha256) {
        fail('clean-VM evidence does not bind the inspected updater manifest');
    }

    if (evidence.updater_signature_sha256 !== updaterSignatureSha256) {
        fail('clean-VM evidence does not bind the inspected updater signature');
    }

    if (
        typeof evidence.windows !== 'string' ||
        evidence.windows.trim() === ''
    ) {
        fail('clean-VM evidence must identify the tested Windows release');
    }

    const testedAt = Date.parse(evidence.tested_at);

    if (!Number.isFinite(testedAt)) {
        fail('clean-VM evidence tested_at must be an ISO-8601 timestamp');
    }

    if (testedAt > now + 5 * 60 * 1000) {
        fail('clean-VM evidence timestamp is in the future');
    }

    if (now - testedAt > MAX_EVIDENCE_AGE_MS) {
        fail('clean-VM evidence is older than 30 days');
    }

    assertExactKeys(
        evidence.checks,
        [
            'install',
            'first_launch',
            'offline_restart',
            'local_backup_restore',
            'signed_update',
            'upgrade',
            'uninstall_data_policy',
        ],
        'clean-VM evidence checks',
    );

    const firstRelease =
        evidence.checks.upgrade === 'not-applicable-first-release' &&
        evidence.checks.signed_update === 'not-applicable-first-release';

    if (
        (evidence.checks.upgrade === 'not-applicable-first-release' ||
            evidence.checks.signed_update === 'not-applicable-first-release') &&
        !firstRelease
    ) {
        fail(
            'upgrade and signed_update must use the same first-release result',
        );
    }

    for (const [name, result] of Object.entries(evidence.checks)) {
        if (
            result !== 'pass' &&
            !(
                (name === 'upgrade' || name === 'signed_update') &&
                result === 'not-applicable-first-release'
            )
        ) {
            fail(`clean-VM check ${name} did not pass`);
        }
    }
}

async function verifyAuthenticode(installer) {
    assertWindowsHost();
    const systemRoot = process.env.SystemRoot ?? process.env.SYSTEMROOT;

    if (typeof systemRoot !== 'string' || systemRoot.trim() === '') {
        fail('SystemRoot is required to locate Windows PowerShell');
    }

    const powershell = path.join(
        systemRoot,
        'System32',
        'WindowsPowerShell',
        'v1.0',
        'powershell.exe',
    );
    await assertRegularFile(powershell, 'Windows PowerShell');

    const command = [
        "$ErrorActionPreference = 'Stop';",
        '$signature = Get-AuthenticodeSignature -LiteralPath $env:MEDISMART_INSTALLER;',
        '[pscustomobject]@{',
        'Status = [string]$signature.Status;',
        'SignerThumbprint = [string]$signature.SignerCertificate.Thumbprint;',
        'SignerSubject = [string]$signature.SignerCertificate.Subject;',
        'TimestampThumbprint = [string]$signature.TimeStamperCertificate.Thumbprint',
        '} | ConvertTo-Json -Compress',
    ].join(' ');
    const output = await new Promise((resolve, reject) => {
        const child = spawn(
            powershell,
            [
                '-NoLogo',
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-Command',
                command,
            ],
            {
                windowsHide: true,
                stdio: ['ignore', 'pipe', 'pipe'],
                env: {
                    MEDISMART_INSTALLER: installer,
                    SystemRoot: systemRoot,
                    TEMP: process.env.TEMP ?? '',
                    TMP: process.env.TMP ?? '',
                },
            },
        );
        let stdout = '';
        let stderr = '';
        const timer = setTimeout(() => {
            child.kill();
            reject(new Error('Authenticode verification timed out'));
        }, 30_000);

        child.stdout.setEncoding('utf8');
        child.stderr.setEncoding('utf8');
        child.stdout.on('data', (chunk) => {
            stdout += chunk;
        });
        child.stderr.on('data', (chunk) => {
            stderr += chunk;
        });
        child.on('error', (error) => {
            clearTimeout(timer);
            reject(error);
        });
        child.on('close', (code) => {
            clearTimeout(timer);

            if (code !== 0) {
                reject(
                    new Error(
                        `Authenticode verification failed: ${stderr.trim() || `exit ${code}`}`,
                    ),
                );

                return;
            }

            resolve(stdout);
        });
    });
    let signature;

    try {
        signature = JSON.parse(output);
    } catch {
        fail('Authenticode verification returned invalid JSON');
    }

    assertExactKeys(
        signature,
        ['Status', 'SignerThumbprint', 'SignerSubject', 'TimestampThumbprint'],
        'Authenticode result',
    );

    if (signature.Status !== 'Valid') {
        fail(`installer Authenticode status is ${signature.Status}`);
    }

    if (!CERTIFICATE_THUMBPRINT.test(signature.SignerThumbprint)) {
        fail('installer has no valid Authenticode signer certificate');
    }

    if (!CERTIFICATE_THUMBPRINT.test(signature.TimestampThumbprint)) {
        fail('installer has no trusted Authenticode timestamp');
    }

    return signature;
}

function usage() {
    return `Usage:
  npm run desktop:release:readiness -- --resources <directory> --expected-version <semver> --installer <signed-installer.exe|msi> --updater-manifest <latest.json> --updater-artifact <bundle.zip> --updater-signature <bundle.zip.sig> --updater-target <windows-x86_64-nsis|windows-x86_64-msi> --updater-artifact-url <https-url> --clean-vm-evidence <evidence.json>

This read-only Windows gate executes only the reviewed packaged binary probes,
requires a valid timestamped Authenticode signature, validates the exact Tauri
updater publication contract, and requires hash-bound clean-VM evidence. It
does not build, sign, upload, or publish an installer.`;
}

async function main() {
    const args = parseCliArguments(
        process.argv.slice(2),
        new Set(['help']),
        new Set([
            'resources',
            'expected-version',
            'installer',
            'updater-manifest',
            'updater-artifact',
            'updater-signature',
            'updater-target',
            'updater-artifact-url',
            'clean-vm-evidence',
        ]),
    );

    if (args.help) {
        process.stdout.write(`${usage()}\n`);

        return;
    }

    requireCliArguments(args, [
        'resources',
        'expected-version',
        'installer',
        'updater-manifest',
        'updater-artifact',
        'updater-signature',
        'updater-target',
        'updater-artifact-url',
        'clean-vm-evidence',
    ]);
    assertWindowsHost();
    const resources = path.resolve(args.resources);
    const installer = path.resolve(args.installer);
    const updaterManifest = path.resolve(args['updater-manifest']);
    const updaterArtifact = path.resolve(args['updater-artifact']);
    const updaterSignature = path.resolve(args['updater-signature']);
    const evidencePath = path.resolve(args['clean-vm-evidence']);
    await assertRegularFile(installer, 'signed installer');
    await assertRegularFile(updaterManifest, 'updater manifest');
    await assertRegularFile(updaterArtifact, 'updater artifact');
    await assertRegularFile(updaterSignature, 'updater signature');
    await assertRegularFile(evidencePath, 'clean-VM evidence');
    await validateReleaseResources(resources, {
        probeBinaries: true,
        expectedApplicationVersion: args['expected-version'],
    });
    const installerSha256 = await sha256File(installer);
    const resourceManifestSha256 = await sha256File(
        path.join(resources, RESOURCE_MANIFEST),
    );
    const updaterArtifactSha256 = await sha256File(updaterArtifact);
    const updaterManifestSha256 = await sha256File(updaterManifest);
    const updaterSignatureSha256 = await sha256File(updaterSignature);
    const signature = await verifyAuthenticode(installer);
    let updaterManifestData;
    let updaterSignatureData;

    try {
        updaterManifestData = JSON.parse(
            await readFile(updaterManifest, 'utf8'),
        );
        updaterSignatureData = (
            await readFile(updaterSignature, 'utf8')
        ).trim();
    } catch (error) {
        fail(`updater publication inputs are invalid: ${error.message}`);
    }

    validateUpdaterReleaseManifest(updaterManifestData, {
        applicationVersion: args['expected-version'],
        target: args['updater-target'],
        artifactUrl: args['updater-artifact-url'],
        signature: updaterSignatureData,
    });
    let evidence;

    try {
        evidence = JSON.parse(await readFile(evidencePath, 'utf8'));
    } catch (error) {
        fail(`clean-VM evidence is not valid JSON: ${error.message}`);
    }

    validateCleanVmEvidence(evidence, {
        applicationVersion: args['expected-version'],
        installerSha256,
        resourceManifestSha256,
        updaterArtifactSha256,
        updaterManifestSha256,
        updaterSignatureSha256,
    });
    process.stdout.write(
        `Release-readiness checks passed for ${args['expected-version']} (${installerSha256}); signer: ${signature.SignerSubject}\n`,
    );
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : null;

if (invokedPath === path.resolve(fileURLToPath(import.meta.url))) {
    main().catch((error) => {
        process.stderr.write(
            `Release-readiness check failed: ${error.message}\n`,
        );
        process.exitCode = 1;
    });
}
