// node --test tools/git-hooks/pre-push.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const hook = join(dirname(fileURLToPath(import.meta.url)), 'pre-push');
const sha = 'a'.repeat(40);
const zero = '0'.repeat(40);

function push(...lines) {
    return spawnSync('bash', [hook, 'origin', 'https://github.com/example/repo.git'], {
        input: lines.map((line) => `${line}\n`).join(''),
        encoding: 'utf8',
    }).status;
}

test('refuses a push to main', () => {
    assert.equal(push(`refs/heads/main ${sha} refs/heads/main ${zero}`), 1);
});

test('refuses a push to main from another local branch', () => {
    assert.equal(push(`refs/heads/feature ${sha} refs/heads/main ${sha}`), 1);
});

test('refuses when any one of several refs is main', () => {
    assert.equal(push(`refs/heads/feature ${sha} refs/heads/feature ${zero}`, `refs/heads/main ${sha} refs/heads/main ${sha}`), 1);
});

test('allows a push to any other branch', () => {
    assert.equal(push(`refs/heads/feature ${sha} refs/heads/feature ${zero}`), 0);
});

test('allows a push with nothing to update', () => {
    assert.equal(push(), 0);
});
