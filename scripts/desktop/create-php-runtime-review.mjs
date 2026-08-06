#!/usr/bin/env node

import path from 'node:path';

import {
    assertWindowsHost,
    createPhpReviewManifest,
    ensureWritableParent,
    parseCliArguments,
    requireCliArguments,
    writePhpReviewManifest,
} from './release-resources.mjs';

function usage() {
    return `Usage:
  npm run desktop:resources:review-php -- \\
    --php-runtime C:\\controlled\\php-8.3 \\
    --output C:\\reviewed\\php-runtime.review.json

This inventories and probes a supplied Windows PHP runtime. It does not download
or establish provenance for PHP. Review the candidate and pin the printed
manifest SHA-256 through the independent release approval process.`;
}

async function main() {
    const args = parseCliArguments(
        process.argv.slice(2),
        new Set(['help']),
        new Set(['php-runtime', 'output']),
    );

    if (args.help) {
        process.stdout.write(`${usage()}\n`);

        return;
    }

    requireCliArguments(args, ['php-runtime', 'output']);
    assertWindowsHost();
    const runtime = path.resolve(args['php-runtime']);
    const output = path.resolve(args.output);
    const relative = path.relative(runtime, output);

    if (
        relative === '' ||
        (!relative.startsWith(`..${path.sep}`) &&
            relative !== '..' &&
            !path.isAbsolute(relative))
    ) {
        throw new Error(
            'write the review manifest outside the PHP runtime so it cannot change the reviewed inventory',
        );
    }

    await ensureWritableParent(output);
    const manifest = await createPhpReviewManifest(runtime);
    const digest = await writePhpReviewManifest(output, manifest);
    process.stdout.write(
        `PHP review candidate written to ${output}\nSHA-256: ${digest}\n`,
    );
}

main().catch((error) => {
    process.stderr.write(`PHP runtime review refused: ${error.message}\n`);
    process.exitCode = 1;
});
