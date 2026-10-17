// node --test tools/claude/workflow-args.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const AsyncFunction = (async () => {}).constructor;

// Distinct values, none inside another, so a prompt carries one only if the script used it.
const paths = {
    repo: '/c/test/repository',
    worktreeRoot: '/c/test/worktree-root',
    taskDir: '/c/test/task-dir',
    scratch: '/c/test/scratch-dir',
    planPath: 'docs/test/plan-under-review.md',
};

const scripts = [
    {
        file: 'implement-plan-staged.js',
        required: ['repo', 'worktreeRoot', 'taskDir', 'planPath'],
        // One stage of two tasks, so the prompts include the worktree setup.
        rest: {
            branch: 'feature',
            mainSha: 'aaaaaaa',
            startHead: 'bbbbbbb',
            prTask: 2,
            stages: [[1, 2]],
            planLabel: 'the test plan',
        },
    },
    {
        file: 'premerge-review.js',
        required: ['repo', 'scratch', 'planPath'],
        rest: { branch: 'feature', base: 'aaaaaaa', pr: 1, label: 'the test plan' },
    },
    {
        file: 'review-plan.template.js',
        required: ['repo', 'scratch', 'planPath'],
        rest: {},
    },
];

// The Workflow tool runs a script's body inside an async function: that is what makes the
// top-level await and the final return legal, and why Node cannot import these files as
// modules. Each case compiles the body the same way: meta loses its export and the tool's
// globals become parameters. The agent stand-in records each prompt and returns null, as a
// skipped agent does.
function run(file, args, prompts = []) {
    const source = readFileSync(join(here, file), 'utf8');
    const body = source.replace(/^export const meta = /m, 'const meta = ');
    assert.notEqual(body, source, `${file} has no export const meta`);
    const script = new AsyncFunction('args', 'agent', 'parallel', 'pipeline', 'phase', 'log', 'budget', 'workflow', body);

    const agent = async (prompt) => {
        prompts.push(prompt);
        return null;
    };
    const parallel = (thunks) => Promise.all(thunks.map((thunk) => Promise.resolve().then(thunk).catch(() => null)));
    const pipeline = (items, ...stages) =>
        Promise.all(
            items.map(async (item, index) => {
                try {
                    let value = item;
                    for (const stage of stages) value = await stage(value, item, index);
                    return value;
                } catch {
                    return null;
                }
            }),
        );
    const quiet = () => {};
    const budget = { total: null, spent: () => 0, remaining: () => Infinity };
    const workflow = async () => null;

    return script(args, agent, parallel, pipeline, quiet, quiet, budget, workflow);
}

// A refusal is the whole message on one line: the argument's name, what it should hold, then
// the pointer to the README. A RegExp given for `message` is matched against the message alone,
// so ^ and $ anchor it there.
const refusal = (name) => ({
    message: new RegExp(`^Workflow args\\.${name} is required: [^\\n]* See tools/claude/README\\.md, "Paths"\\.$`),
});

for (const { file, required, rest } of scripts) {
    const complete = { ...rest, ...Object.fromEntries(required.map((name) => [name, paths[name]])) };

    for (const name of required) {
        test(`${file} refuses to start without args.${name}`, async () => {
            const missing = { ...complete };
            delete missing[name];
            for (const args of [missing, { ...complete, [name]: '' }]) {
                const prompts = [];
                await assert.rejects(run(file, args, prompts), refusal(name));
                assert.equal(prompts.length, 0, 'an agent started before the check');
            }
        });
    }

    test(`${file} refuses to start when it gets no args at all`, async () => {
        await assert.rejects(run(file, undefined), refusal('repo'));
    });

    test(`${file} starts with its paths and gives its agents those paths only`, async () => {
        const prompts = [];
        await run(file, complete, prompts);
        assert.ok(prompts.length > 0, 'no agent started');
        const sent = prompts.join('\n');
        for (const name of required) {
            assert.ok(sent.includes(paths[name]), `no prompt carries args.${name}`);
        }
        // None of the values passed in lies under a Windows home directory, so such a path in
        // a prompt can only be a literal left in the script.
        assert.doesNotMatch(sent, /(?:\/c|[a-z]:)[\\/]users[\\/]/i);
    });
}
