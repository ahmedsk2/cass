// node --test tools/docs/check-pointers.test.mjs
//
// Each rule of check-pointers.mjs on a small fixture tree: a tree that resolves and a tree that
// breaks it. The last case runs the checker over this repository itself.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const script = join(here, 'check-pointers.mjs');
const repo = resolve(here, '..', '..');

const RUNBOOK = [
    '# Runbook',
    '',
    '## Every release',
    '',
    '1. Merge.',
    '2. Deploy:',
    '',
    '```bash',
    'sudo docker exec "$C" su-exec app php artisan cass:health',
    '```',
    '',
    '## Restore drill from the off-host copy — monthly, and not optional',
    '',
    'Text (see "Every release") and (see **Every release**), under "Every release".',
    '',
    '- **Rotate** the key.',
    '',
].join('\n');

const BACKLOG = [
    '# Backlog',
    '',
    '| Item | Area | Trigger | The one-line change | Link |',
    '| --- | --- | --- | --- | --- |',
    '| Tracker events carry no release | Monitoring | A release | A variable | runbook "Every release" |',
    '',
].join('\n');

const HANDOVER = ['# Handover', '', '| # | Item | Who | Status |', '|---|---|---|---|', '| B8 | Backups | Owner | Open |', ''].join('\n');

function tree(extra = {}) {
    const root = mkdtempSync(join(tmpdir(), 'pointers-'));
    const files = {
        'docs/runbooks/deploy-production.md': RUNBOOK,
        'docs/superpowers/plans/backlog.md': BACKLOG,
        'docs/HANDOVER.md': HANDOVER,
        'docs/decisions/0001-a-decision.md': '# A decision\n',
        'app/Real.php': '<?php\n',
        ...extra,
    };
    for (const [path, content] of Object.entries(files)) {
        mkdirSync(join(root, dirname(path)), { recursive: true });
        writeFileSync(join(root, path), content);
    }
    return root;
}

function check(root) {
    const run = spawnSync(process.execPath, [script, root], { encoding: 'utf8' });
    return { status: run.status, output: run.stdout + run.stderr };
}

function passes(name, extra) {
    test(`${name}: resolves`, () => {
        const { status, output } = check(tree(extra));
        assert.equal(status, 0, output);
    });
}

function fails(name, extra, message) {
    test(`${name}: broken`, () => {
        const { status, output } = check(tree(extra));
        assert.equal(status, 1, output);
        assert.match(output, message);
    });
}

passes('links and anchors', { 'docs/a.md': '[r](runbooks/deploy-production.md#restore-drill-from-the-off-host-copy--monthly-and-not-optional)\n' });
fails('links and anchors', { 'docs/a.md': '[r](runbooks/deploy-production.md#no-such-anchor) and [m](missing.md)\n' }, /missing anchor[\s\S]*missing file/);

passes('backticked paths', { 'docs/a.md': '`app/Real.php:12` and `docs/gone.md` at commit `abc1234`\n' });
fails('backticked paths', { 'docs/a.md': '`app/Gone.php`\n' }, /missing path: app\/Gone\.php/);

passes('runbook titles', { 'docs/a.md': 'The runbook\'s "Every release" and "Restore drill from the off-host copy", and runbook, **Every\nrelease** step 2.\n' });
fails('runbook titles, chained', { 'docs/a.md': 'runbook "Every release" and "No such section"\n' }, /runbook title not found: "No such section"/);
fails('runbook titles, wrapped', { 'docs/a.md': 'the runbook\'s "Every\nreleases" here\n' }, /runbook title not found: "Every releases"/);

fails('the runbook\'s own references', {
    'docs/runbooks/deploy-production.md': RUNBOOK + 'More (see "No such") and (see **Nor this**).\n',
}, /cross-reference not found: No such[\s\S]*cross-reference not found: Nor this/);

fails('step numbers', { 'docs/a.md': 'runbook "Every release" step 3\n' }, /"Every release" has no step 3/);

passes('backlog areas and items', { 'docs/a.md': '(backlog, "Monitoring", "Tracker events carry no release")\n' });
fails('backlog areas and items', { 'docs/a.md': 'backlog "Nowhere"\n' }, /backlog area or item not found: "Nowhere"/);
fails('backlog line numbers', { 'docs/a.md': 'see backlog.md:12\n' }, /a backlog line number/);

passes('decisions and handover rows', { 'docs/a.md': 'decision 0001, and HANDOVER, B8\n' });
fails('decisions and handover rows', { 'docs/a.md': 'decision 0002, and HANDOVER, B9\n' }, /decision not found: 0002[\s\S]*HANDOVER row not found: B9/);

passes('citations in code', { 'app/Cited.php': '<?php\n// The runbook\'s "Every\n// release" says so.\n' });
fails('citations in code', { 'app/Cited.php': '<?php\n// (runbook "No such\n// section")\n' }, /app\/Cited\.php:2: runbook title not found: "No such section"/);

const quick = (command) => RUNBOOK.replace('## Every release', [
    '## Quick procedures',
    '',
    '### Q1. Health',
    '',
    `- **a.** \`${command}\` ([Every release](#every-release))`,
    '',
    '## Every release',
].join('\n'));
passes('quick procedures', { 'docs/runbooks/deploy-production.md': quick('sudo docker exec "$C" su-exec app php artisan cass:health') });
fails('quick procedures', { 'docs/runbooks/deploy-production.md': quick('sudo docker exec "$C" su-exec app php artisan cass:nope') }, /Q1\. Health: command not verbatim/);

test('this repository has no broken pointer', () => {
    const { status, output } = check(repo);
    assert.equal(status, 0, output);
});
