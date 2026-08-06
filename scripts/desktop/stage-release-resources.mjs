#!/usr/bin/env node

import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    parseCliArguments,
    requireCliArguments,
    stageReleaseResources,
} from './release-resources.mjs';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const defaultSource = path.resolve(scriptDirectory, '..', '..');

function usage() {
    return `Usage:
  npm run desktop:resources:stage -- \\
    --php-runtime C:\\reviewed\\php-8.3 \\
    --php-review C:\\reviewed\\php-runtime.review.json \\
    --php-review-sha256 <sha256> \\
    --composer-phar C:\\reviewed\\composer.phar \\
    --composer-sha256 <sha256> \\
    --cloudflared C:\\reviewed\\cloudflared.exe \\
    --cloudflared-sha256 <sha256> [--replace]

Optional paths:
  --source <checkout>       defaults to the repository root
  --output <directory>     defaults to src-tauri/resources

The command is Windows-only. It never downloads PHP, Composer, or cloudflared.`;
}

async function main() {
    const args = parseCliArguments(
        process.argv.slice(2),
        new Set(['help', 'replace']),
        new Set([
            'source',
            'output',
            'php-runtime',
            'php-review',
            'php-review-sha256',
            'composer-phar',
            'composer-sha256',
            'cloudflared',
            'cloudflared-sha256',
        ]),
    );

    if (args.help) {
        process.stdout.write(`${usage()}\n`);

        return;
    }

    requireCliArguments(args, [
        'php-runtime',
        'php-review',
        'php-review-sha256',
        'composer-phar',
        'composer-sha256',
        'cloudflared',
        'cloudflared-sha256',
    ]);
    const sourceRoot = path.resolve(args.source ?? defaultSource);
    const output = path.resolve(
        args.output ?? path.join(sourceRoot, 'src-tauri', 'resources'),
    );
    const published = await stageReleaseResources({
        sourceRoot,
        output,
        phpRuntime: args['php-runtime'],
        phpReview: args['php-review'],
        phpReviewSha256: args['php-review-sha256'],
        composerPhar: args['composer-phar'],
        composerSha256: args['composer-sha256'],
        cloudflared: args.cloudflared,
        cloudflaredSha256: args['cloudflared-sha256'],
        replace: args.replace === true,
    });
    process.stdout.write(
        `Validated desktop release resources published to ${published}\n`,
    );
}

main().catch((error) => {
    process.stderr.write(`Desktop release staging refused: ${error.message}\n`);
    process.exitCode = 1;
});
