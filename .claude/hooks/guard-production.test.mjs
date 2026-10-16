// node --test .claude/hooks/guard-production.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, utimesSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const hook = join(dirname(fileURLToPath(import.meta.url)), 'guard-production.mjs');

function project() {
    const dir = mkdtempSync(join(tmpdir(), 'cass-guard-'));
    mkdirSync(join(dir, '.claude'));
    return dir;
}

function run(dir, payload) {
    const result = spawnSync(process.execPath, [hook], {
        input: typeof payload === 'string' ? payload : JSON.stringify(payload),
        env: { ...process.env, CLAUDE_PROJECT_DIR: dir },
        encoding: 'utf8',
    });
    return result.status;
}

const bash = (command) => ({ tool_name: 'Bash', tool_input: { command, description: 'x' } });

test('lets ordinary commands through', () => {
    const dir = project();
    for (const command of ['php artisan test', 'git status', 'npm run build', 'grep -rn sshd docs']) {
        assert.equal(run(dir, bash(command)), 0, command);
    }
    rmSync(dir, { recursive: true });
});

test('refuses commands that reach production while locked', () => {
    const dir = project();
    for (const command of [
        'ssh -i ~/.ssh/key ubuntu@host true',
        "ssh host 'bash -s' < tools/claude/deploy.sh",
        'scp file host:/tmp',
        'rsync -a dir host:/srv',
        'oci compute instance list',
        'bash tools/claude/deploy.sh',
        'curl -s https://coolify.example/api/v1/deploy',
        'echo "harmless" && ssh host reboot',
        '(cd /tmp; ssh host true)',
    ]) {
        assert.equal(run(dir, bash(command)), 2, command);
    }
    rmSync(dir, { recursive: true });
});

test('refuses the Oracle Cloud and Cloudflare API tools while locked', () => {
    const dir = project();
    assert.equal(run(dir, { tool_name: 'mcp__oracle-oci__invoke_oci_api', tool_input: {} }), 2);
    assert.equal(run(dir, { tool_name: 'mcp__cloudflare-api__execute', tool_input: {} }), 2);
    assert.equal(run(dir, { tool_name: 'mcp__cloudflare-docs__search_cloudflare_documentation', tool_input: {} }), 0);
    rmSync(dir, { recursive: true });
});

test('allows production access for 60 minutes after the owner unlocks it', () => {
    const dir = project();
    const unlock = join(dir, '.claude', 'deploy-unlocked');
    writeFileSync(unlock, '');
    assert.equal(run(dir, bash('ssh host true')), 0);
    assert.equal(run(dir, { tool_name: 'mcp__oracle-oci__invoke_oci_api', tool_input: {} }), 0);

    const twoHoursAgo = new Date(Date.now() - 2 * 3600 * 1000);
    utimesSync(unlock, twoHoursAgo, twoHoursAgo);
    assert.equal(run(dir, bash('ssh host true')), 2);
    rmSync(dir, { recursive: true });
});

test('refuses rather than guesses when the input is not JSON', () => {
    const dir = project();
    assert.equal(run(dir, 'not json'), 2);
    rmSync(dir, { recursive: true });
});
