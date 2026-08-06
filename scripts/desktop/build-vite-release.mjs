#!/usr/bin/env node

import path from 'node:path';
import { pathToFileURL } from 'node:url';

const WAYFINDER_PLUGIN_NAME = '@laravel/vite-plugin-wayfinder';

async function withoutWayfinder(value, state) {
    const resolved = await value;

    if (Array.isArray(resolved)) {
        const filtered = [];

        for (const item of resolved) {
            const child = await withoutWayfinder(item, state);

            if (Array.isArray(child)) {
filtered.push(...child);
} else if (child) {
filtered.push(child);
}
        }

        return filtered;
    }

    if (
        resolved &&
        typeof resolved === 'object' &&
        resolved.name === WAYFINDER_PLUGIN_NAME
    ) {
        state.removed += 1;

        return null;
    }

    return resolved;
}

async function main() {
    if (process.argv.length !== 3) {
        throw new Error(
            'internal Vite release helper requires exactly one repository-root argument',
        );
    }

    const sourceRoot = path.resolve(process.argv[2]);
    const viteModule = pathToFileURL(
        path.join(
            sourceRoot,
            'node_modules',
            'vite',
            'dist',
            'node',
            'index.js',
        ),
    ).href;
    const { build, loadConfigFromFile } = await import(viteModule);
    const environment = {
        command: 'build',
        mode: 'production',
        isSsrBuild: false,
        isPreview: false,
    };
    const loaded = await loadConfigFromFile(
        environment,
        path.join(sourceRoot, 'vite.config.ts'),
        sourceRoot,
    );

    if (!loaded) {
        throw new Error('Vite could not load vite.config.ts');
    }

    const state = { removed: 0 };
    const plugins = await withoutWayfinder(loaded.config.plugins ?? [], state);

    if (state.removed !== 1) {
        throw new Error(
            `expected exactly one ${WAYFINDER_PLUGIN_NAME} plugin, removed ${state.removed}`,
        );
    }

    await build({
        ...loaded.config,
        root: sourceRoot,
        configFile: false,
        envFile: false,
        mode: 'production',
        plugins,
        build: {
            ...(loaded.config.build ?? {}),
            sourcemap: false,
        },
    });
}

main().catch((error) => {
    process.stderr.write(
        `Isolated Vite release build failed: ${error.message}\n`,
    );
    process.exitCode = 1;
});
