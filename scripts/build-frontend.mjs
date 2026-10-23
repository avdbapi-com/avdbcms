import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const isWindows = process.platform === 'win32';

const command = isWindows ? 'powershell' : 'bash';
const args = isWindows
    ? ['-ExecutionPolicy', 'Bypass', '-File', 'scripts/build-frontend.ps1']
    : ['scripts/build-frontend.sh'];

const result = spawnSync(command, args, {
    cwd: rootDir,
    stdio: 'inherit',
    shell: false,
});

if (result.error) {
    console.error(result.error.message);
    process.exit(1);
}

process.exit(result.status ?? 1);
