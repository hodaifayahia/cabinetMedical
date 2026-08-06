#!/usr/bin/env node

import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    parseCliArguments,
    validateReleaseResources,
} from './release-resources.mjs';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const defaultResources = path.resolve(
    scriptDirectory,
    '..',
    '..',
    'src-tauri',
    'resources',
);

function usage() {
    return `Usage:
  npm run desktop:resources:validate -- [--resources <directory>] [--expected-version <semver>] [--probe-binaries]

Static validation is cross-platform and does not execute packaged binaries.
--probe-binaries additionally executes the reviewed Windows PHP runtime and
inspects the SQLite template, so use it only on the controlled Windows host.`;
}

async function main() {
    const args = parseCliArguments(
        process.argv.slice(2),
        new Set(['help', 'probe-binaries']),
        new Set(['resources', 'expected-version']),
    );

    if (args.help) {
        process.stdout.write(`${usage()}\n`);

        return;
    }

    const resources = path.resolve(args.resources ?? defaultResources);
    await validateReleaseResources(resources, {
        probeBinaries: args['probe-binaries'] === true,
        expectedApplicationVersion: args['expected-version'] ?? null,
    });
    process.stdout.write(
        `Desktop release resources are complete and uncontaminated: ${resources}\n`,
    );
}

main().catch((error) => {
    process.stderr.write(
        `Desktop release resource validation failed: ${error.message}\n`,
    );
    process.exitCode = 1;
});
