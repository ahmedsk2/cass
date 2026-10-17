// Workflow tool script. Implements a reviewed plan in dependency stages: a stage with one task
// runs in the main tree; a stage with two tasks runs them in parallel worktrees, each reviewed,
// then merges them one after the other. Each task = implement (max) -> one combined spec +
// quality reviewer (high) -> one fix round -> recheck.
// args: { repo, worktreeRoot, branch, mainSha, startHead, prTask, stages, taskDir, planPath, planLabel }
//   repo, worktreeRoot: required Git Bash paths; a parallel task's worktree is <worktreeRoot>/CASS-wt-N
//   stages:  [[1],[2,5],[3,6],...]  task numbers; pairs run in parallel
//   taskDir: directory holding header.md and task-NN.md from tools/claude/split-plan.sh
export const meta = {
  name: 'implement-plan-staged',
  description: 'Implement a reviewed CASS plan in dependency stages: independent tasks in parallel worktrees, each with implement -> combined review -> one fix round; parallel branches merged sequentially (Opus)',
  phases: [{ title: 'Stages', detail: 'one progress group per stage' }],
}

// Paths come only from args: a missing one stops the run here, before any agent starts.
const required = (name, example) => {
  const value = args?.[name]
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error(`Workflow args.${name} is required: ${example}. See tools/claude/README.md, "Paths".`)
  }
  return value
}

const REPO = required('repo', 'the repository path in Git Bash form, e.g. /c/Users/you/projects/CASS')
const WT_ROOT = required('worktreeRoot', 'the directory that receives the CASS-wt-N worktrees, e.g. /c/Users/you/projects')
const TASKS = args.taskDir
const BRANCH = args.branch
const PR_TASK = args.prTask
const STAGES = args.stages
const PLAN = args.planPath
const LABEL = args.planLabel

const wtDir = (n) => `${WT_ROOT}/CASS-wt-${n}`
const wtBranch = (n) => `${BRANCH}-task-${n}`
const taskFile = (n) => `${TASKS}/task-${String(n).padStart(2, '0')}.md`

const COMMON = `
Project: CASS v2, a multi-tenant Laravel 13 + Filament 5 + Livewire 4 + Tailwind 4 app. Main repository ${REPO} (use the Bash tool with Git Bash paths; the shell cwd resets between calls, so start every command with cd <the directory you are told to work in>). main is at ${args.mainSha} and deployed. ${LABEL} is being implemented on branch ${BRANCH}, whose starting head is ${args.startHead} (main plus the committed plan); the suite there is green, Pint clean and Larastan level 6 clean.

Read these files completely before doing anything: ${REPO}/CLAUDE.md (project rules), ${TASKS}/header.md (the plan header: goal, architecture, environment facts, what is NOT in scope, the file-structure map and the facts verified in vendor), and the task file named below. The task files are exact excerpts of ${PLAN}; the plan was adversarially reviewed and corrected, so follow it literally unless the installed code proves it wrong. When something in the task text conflicts with the real code in the repository or in vendor/, the code is authoritative: adapt minimally, keep the intent, and report the deviation. Earlier tasks of this plan may have made small reviewed changes the plan text does not reflect; read the current code of anything you touch. Test counts stated in the plan are approximate: rc=0 is the gate.

Hard rules: Composer must be invoked through the composer.phar path in CLAUDE.md (never composer.bat). Every new PHP file starts with declare(strict_types=1). Verify by exit code. IMPORTANT: other agents share this machine's /tmp, so write every log to a file that carries your task number, e.g. php artisan test > /tmp/t-task-N.log 2>&1; echo "rc=$?" (replace N with your task number); never read a log you did not write. php - (stdin scripts) does not work on this machine: write ad-hoc PHP to a file under ${TASKS}/tmp/ and run php <file>. Never touch Legacy/, assets/, legacy-review.md; never stage .env, database/database.sqlite or public/build; never run commands against production; never run npx playwright locally (the browser suite is CI-only); nobody can answer questions during this run: if something is genuinely blocking, stop and report status BLOCKED with the exact error and what you tried.

REPORT FORMAT (critical): your final answer is parsed as JSON by a program. Every string value must be ONE line: no newline characters, no tab characters, no backslashes (write paths with forward slashes), no unescaped double quotes. Put multiple points into array items, one short line each. Keep the whole report under 60 lines total. Always fill status and headSha (run git rev-parse --short HEAD in the directory you worked in, at the end).
`

const IMPL_SCHEMA = {
  type: 'object',
  properties: {
    status: { type: 'string', enum: ['DONE', 'DONE_WITH_CONCERNS', 'BLOCKED', 'NEEDS_CONTEXT'] },
    headSha: { type: 'string', description: 'git rev-parse --short HEAD after your final commit' },
    summary: { type: 'array', items: { type: 'string' }, description: 'one short line per item, max 12 items' },
    testsBefore: { type: 'string', description: 'one line: rc and key error proving the new tests failed first' },
    gates: { type: 'string', description: 'one line: test/phpstan/pint rc values and test count' },
    deviations: { type: 'array', items: { type: 'string' } },
    concerns: { type: 'array', items: { type: 'string' } },
    prUrl: { type: 'string', description: 'PR task only: the pull request URL' },
  },
  required: ['status', 'headSha', 'summary'],
}

const ISSUES_SCHEMA = {
  type: 'object',
  properties: {
    ok: { type: 'boolean', description: 'true when nothing of critical or important severity remains and the implementation matches the task' },
    issues: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          id: { type: 'string' },
          severity: { type: 'string', enum: ['critical', 'important', 'minor'] },
          kind: { type: 'string', enum: ['spec', 'quality'] },
          file: { type: 'string', description: 'repo-relative path with forward slashes' },
          line: { type: 'integer' },
          problem: { type: 'string', description: 'one line' },
          fix: { type: 'string', description: 'one line, concrete' },
        },
        required: ['id', 'severity', 'kind', 'file', 'problem', 'fix'],
      },
    },
    notes: { type: 'string' },
  },
  required: ['ok', 'issues'],
}

const FIX_SCHEMA = {
  type: 'object',
  properties: {
    headSha: { type: 'string' },
    applied: { type: 'array', items: { type: 'string' } },
    notApplied: { type: 'array', items: { type: 'string' } },
    gates: { type: 'string' },
  },
  required: ['headSha', 'applied'],
}

const MERGE_SCHEMA = {
  type: 'object',
  properties: {
    status: { type: 'string', enum: ['MERGED', 'BLOCKED'] },
    headSha: { type: 'string' },
    conflicts: { type: 'array', items: { type: 'string' }, description: 'one line per conflicted file and how it was resolved' },
    gates: { type: 'string' },
    notes: { type: 'string' },
  },
  required: ['status', 'headSha'],
}

const setupText = (n, base) => `WORKTREE SETUP (this task runs in parallel with another task, so it gets its own worktree and branch). From ${REPO}: run git worktree add -b ${wtBranch(n)} ${wtDir(n)} ${base} (if the directory or branch already exists from an earlier attempt, remove them first with git worktree remove --force ${wtDir(n)} and git branch -D ${wtBranch(n)}). Then, inside ${wtDir(n)}: cp ${REPO}/.env .env; composer install (through the phar) > /tmp/ci-${n}.log 2>&1; echo "rc=$?"; npm ci > /tmp/npm-${n}.log 2>&1; echo "rc=$?"; npm run build > /tmp/build-${n}.log 2>&1; echo "rc=$?"; then php artisan test > /tmp/t-task-${n}.log 2>&1; echo "rc=$?" must be 0 before you change anything. EVERYTHING you do for this task happens inside ${wtDir(n)} on branch ${wtBranch(n)}; never cd into ${REPO} again except for the worktree command, and never commit to ${BRANCH} yourself. Report the head SHA of ${wtBranch(n)}.`

const inTreeText = (base) => `Work directly in ${REPO} on branch ${BRANCH}. Confirm a clean tree (git branch --show-current; git status --short) and that HEAD is ${base}; if the tree is dirty or HEAD differs, stop and report BLOCKED with what you see.`

const implPrompt = (n, base, parallel) => `${COMMON}
You are the implementer for Task ${n}. Task file: ${taskFile(n)}.

${parallel ? setupText(n, base) : inTreeText(base)}

Follow every checkbox step of the task in order: write the failing tests first and run them to see them fail for the stated reason, implement the code exactly as given (adapting only where the real code or vendor proves the plan wrong), run the tests to see them pass, run Pint and Larastan, and commit with the message the task gives (keep its trailer). Verify every expected output the task states. Do not skip a step, do not add unrequested features, do not refactor code outside the task. If a step's expected output differs, find the cause and fix it at the cause; if you cannot, report BLOCKED.

${n === PR_TASK ? 'This task includes running the suite against MySQL through docker-compose.dev.yml, updating the backlog, pushing the branch, watching CI, and opening the pull request with gh (its body must end with the line "🤖 Generated with [Claude Code](https://claude.com/claude-code)"). Do not merge and do not deploy. If CI fails, read the failing job log, fix at the cause, commit, push again and watch again (at most two such rounds); report the final run result and the PR URL.' : 'Do not push.'}

Self-review before reporting: all files in the task's Files list exist with the specified content; every new test was seen failing then passing; the full suite, Larastan (level 6) and Pint exit 0; git status is clean after the commit; nothing private is staged.`

const reviewPrompt = (n, dir, branch, base, head, impl) => `${COMMON}
You are the single reviewer for Task ${n}, covering BOTH spec compliance and code quality. READ-ONLY: do not edit files, do not commit, do not push. Task file: ${taskFile(n)}. Work in ${dir} on branch ${branch}. Git range to review: ${base}..${head}.

Implementer report (do not trust it):
${JSON.stringify(impl)}

Part 1, spec compliance (kind "spec"): read the actual diff (git diff ${base}..${head}) and the touched files and compare with the task text: every listed file created or modified as specified, every test present with the specified assertions, every expected behaviour implemented, nothing extra (unrequested features, extra endpoints, scope from later plans). Deviations are acceptable when the real code or vendor required them; flag them only if wrong.

Part 2, code quality (kind "quality"): correctness (bugs, edge cases, error handling, races, transactions), security and tenant isolation (policies on every ability including deleteAny and bulk actions, tenancy scoping across the panels, blind review, token hashing/single use/expiry, mass assignment, XSS in Blade and mail, rate limits keyed on App\\Support\\ClientIp, scheduler idempotency), test quality (do tests verify real behaviour and would they fail if the feature broke; time or database-engine dependence; fakes that mask the path under test), Larastan-friendly types, single-responsibility files. Do NOT report style preferences or things the plan deliberately defers.

Then run the gates yourself in ${dir}: php artisan test > /tmp/t-review-${n}.log 2>&1; echo "rc=$?"; ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; ./vendor/bin/pint --test. Report ok=true only if the implementation matches the task, nothing of critical or important severity remains and all gates pass. Each issue must cite file and line and give the concrete fix; mark pure nits as minor.`

const fixPrompt = (n, dir, branch, issues) => `${COMMON}
You are fixing verified review findings on Task ${n} (task file ${taskFile(n)}) in ${dir} on branch ${branch}. Apply every fix below. If a fix needs a new or changed test, write the test first and see it fail, then fix. After all fixes: php artisan test, ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G and ./vendor/bin/pint must all exit 0; then commit once with a message starting "fix(task ${n}):" summarizing the fixes, keeping the same Co-Authored-By trailer the task's commit used. ${n === PR_TASK ? 'Because this task already pushed the branch, push this fix commit too and re-check CI.' : 'Do not push.'} If a fix is wrong or would break something, do not apply it and explain in notApplied.

Findings:
${JSON.stringify(issues)}`

const recheckPrompt = (n, dir, branch, issues, fix) => `${COMMON}
You are re-checking fixes on Task ${n} in ${dir} on branch ${branch}. READ-ONLY. The fixer reports:
${JSON.stringify(fix)}

For each finding below, verify in the code at HEAD that it is actually resolved (read the code, run the relevant tests), then run all three gates. ok=true only if every finding is resolved and gates pass; otherwise return the remaining findings with updated file and line and fixes.

Findings that were to be fixed:
${JSON.stringify(issues)}`

const mergePrompt = (n, base) => `${COMMON}
You are merging the finished Task ${n} into the plan branch. Work in ${REPO}. Confirm git branch --show-current is ${BRANCH}, git status --short is empty and HEAD is ${base}; otherwise report BLOCKED. Then: git merge --no-ff ${wtBranch(n)} -m "merge: ${LABEL} task ${n}" (add the trailer line Co-Authored-By: Claude Opus 5 <noreply@anthropic.com> to the message). If there are conflicts, resolve every one by keeping BOTH sides' intent (both tasks' routes, helpers, language keys, migrations, relation managers, navigation items, backlog lines and tests must survive; read the task file ${taskFile(n)} and the other task's changes with git log --oneline ${base}~1..${base} and git diff to understand what each side added); never discard a test or a migration to make a conflict go away. Watch for SILENT semantic conflicts git does not flag: a PHP config or language array with the same top-level key defined twice after an auto-merge (the second definition shadows the first), duplicate use-imports, two migrations sharing a timestamp prefix. After the merge (or after resolving): run php artisan test > /tmp/t-merge-${n}.log 2>&1; echo "rc=$?"; ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; ./vendor/bin/pint --test; all must exit 0 (fix at the cause and add one "fix(merge task ${n}):" commit if needed). Then remove the worktree: git worktree remove --force ${wtDir(n)} and git branch -D ${wtBranch(n)}. Report MERGED with the new head SHA, one line per conflicted file with how it was resolved, and the gate rc values; report BLOCKED with the exact error if you cannot get the gates green.`

const runTask = async (n, dir, branch, base, parallel, ph) => {
  log(`Task ${n}: implementing from ${base}${parallel ? ' in worktree' : ''}`)
  const impl = await agent(implPrompt(n, base, parallel), { label: `implement:task-${n}`, phase: ph, schema: IMPL_SCHEMA, model: 'opus', effort: 'max' })
  if (!impl) return { task: n, halted: 'implementer returned no result' }
  if (impl.status === 'BLOCKED' || impl.status === 'NEEDS_CONTEXT') return { task: n, halted: impl.status, impl }
  let head = impl.headSha

  log(`Task ${n}: reviewing ${base}..${head}`)
  const review = await agent(reviewPrompt(n, dir, branch, base, head, impl), { label: `review:task-${n}`, phase: ph, schema: ISSUES_SCHEMA, model: 'opus', effort: 'high' })
  let issues = review && !review.ok ? review.issues.filter((i) => i.severity !== 'minor' || i.kind === 'spec') : []
  const minor = review ? review.issues.filter((i) => i.severity === 'minor' && i.kind !== 'spec') : []

  let round = null
  if (issues.length > 0) {
    log(`Task ${n}: fix round (${issues.length} findings)`)
    const fix = await agent(fixPrompt(n, dir, branch, issues), { label: `fix:task-${n}`, phase: ph, schema: FIX_SCHEMA, model: 'opus', effort: 'max' })
    if (fix && fix.headSha) head = fix.headSha
    const recheck = fix ? await agent(recheckPrompt(n, dir, branch, issues, fix), { label: `recheck:task-${n}`, phase: ph, schema: ISSUES_SCHEMA, model: 'opus', effort: 'high' }) : null
    round = { applied: fix ? fix.applied : [], notApplied: fix ? fix.notApplied || [] : [], headSha: head, remaining: recheck ? recheck.issues.length : -1 }
    issues = recheck && !recheck.ok ? recheck.issues : []
  }

  return {
    task: n,
    status: impl.status,
    range: `${base}..${head}`,
    head,
    summary: impl.summary,
    deviations: impl.deviations || [],
    concerns: impl.concerns || [],
    reviewOk: review ? review.ok : null,
    fixRound: round,
    unresolved: issues,
    minorDeferred: minor.map((i) => `${i.file}:${i.line || 0} ${i.problem}`),
    prUrl: impl.prUrl || null,
    halted: issues.length > 0 ? 'unresolved findings after the fix round' : null,
  }
}

const results = []
let halted = null
let head = args.startHead
let prUrl = null

for (let s = 0; s < STAGES.length; s++) {
  const tasks = STAGES[s]
  const ph = `Stage ${s + 1}`
  phase(ph)
  if (tasks.length === 1) {
    const r = await runTask(tasks[0], REPO, BRANCH, head, false, ph)
    results.push(r)
    if (r.halted) { halted = { stage: s + 1, task: r.task, reason: r.halted, detail: r.impl || r.unresolved }; break }
    head = r.head
    if (r.prUrl) prUrl = r.prUrl
    log(`Stage ${s + 1}: task ${tasks[0]} done at ${head}`)
    continue
  }
  const base = head
  const parallelResults = await parallel(tasks.map((n) => () => runTask(n, wtDir(n), wtBranch(n), base, true, ph)))
  const done = parallelResults.filter(Boolean)
  results.push(...done)
  const bad = done.find((r) => r.halted) || (done.length < tasks.length ? { task: 'unknown', halted: 'a parallel task returned no result' } : null)
  if (bad) { halted = { stage: s + 1, task: bad.task, reason: bad.halted, detail: bad.impl || bad.unresolved }; break }
  for (const n of tasks) {
    log(`Stage ${s + 1}: merging task ${n} into ${BRANCH} at ${head}`)
    const m = await agent(mergePrompt(n, head), { label: `merge:task-${n}`, phase: ph, schema: MERGE_SCHEMA, model: 'opus', effort: 'high' })
    if (!m || m.status !== 'MERGED') { halted = { stage: s + 1, task: n, reason: 'merge failed', detail: m }; break }
    head = m.headSha
    results.push({ task: `merge-${n}`, status: 'MERGED', head, conflicts: m.conflicts || [], gates: m.gates || '' })
  }
  if (halted) break
  log(`Stage ${s + 1}: tasks ${tasks.join(', ')} merged; head ${head}`)
}

return { results, halted, head, prUrl }
