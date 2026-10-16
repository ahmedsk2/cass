// PreToolUse hook: refuse anything that reaches production unless the owner
// unlocked it in the last 60 minutes with `touch .claude/deploy-unlocked`.
// "Reaches production" means a Bash command that names ssh, scp, sftp, rsync,
// the OCI CLI, deploy.sh or Coolify, or any call to the Oracle Cloud or
// Cloudflare API tools. Exit 2 blocks the call and shows stderr to the model.
// The tool input arrives as JSON on stdin and is parsed, not grepped, so quoting
// inside a command cannot hide what follows it.
import { readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const UNLOCK_SECONDS = 3600;

const BASH_PATTERNS = [
    /(^|[^\w.-])(ssh|scp|sftp|rsync)\s/,
    /(^|[^\w.-])oci\s/,
    /deploy\.sh/,
    /coolify/i,
];

const PRODUCTION_TOOLS = /^mcp__(oracle-oci|cloudflare-api)__/;

let input = {};
try {
    input = JSON.parse(readFileSync(0, 'utf8') || '{}');
} catch {
    process.stderr.write('guard-production: could not parse the hook input; refusing to guess.\n');
    process.exit(2);
}

const tool = String(input.tool_name ?? '');
const command = String(input.tool_input?.command ?? '');

const reachesProduction = PRODUCTION_TOOLS.test(tool)
    || (tool === 'Bash' && BASH_PATTERNS.some((pattern) => pattern.test(command)));

if (! reachesProduction) {
    process.exit(0);
}

const projectDir = process.env.CLAUDE_PROJECT_DIR || process.cwd();
const unlock = join(projectDir, '.claude', 'deploy-unlocked');

try {
    const ageSeconds = (Date.now() - statSync(unlock).mtimeMs) / 1000;
    if (ageSeconds >= 0 && ageSeconds < UNLOCK_SECONDS) {
        process.exit(0);
    }
} catch {
    // No unlock file: locked.
}

process.stderr.write(
    'Production access is locked. The owner unlocks it for 60 minutes with: touch .claude/deploy-unlocked\n'
    + 'If this command does not reach production (for example a commit message that names deploy.sh or Coolify), '
    + 'write that text to a file and pass the file instead (git commit -F <file>).\n',
);
process.exit(2);
