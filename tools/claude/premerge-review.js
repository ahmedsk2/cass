// Workflow tool script. Maximum-effort three-lens review of a whole plan branch, skeptic
// verification, one fixer that applies confirmed findings test-first, pushes and watches CI.
// args: { branch, base, pr, planPath, label, deferredPath?, repo?, scratch? }
export const meta = {
  name: 'premerge-review',
  description: 'Maximum-effort three-lens review of a whole plan branch diff, skeptic verification, one fixer that applies confirmed findings test-first, pushes and watches CI',
  phases: [
    { title: 'Review', detail: 'security/tenancy, correctness/runtime, tests/coverage over the full branch diff', model: 'opus' },
    { title: 'Verify', detail: 'one skeptic per lens tries to refute each finding', model: 'opus' },
    { title: 'Fix', detail: 'one fixer applies confirmed findings test-first, runs gates, pushes, watches CI', model: 'opus' },
  ],
}

const REPO = args.repo || '/c/Users/ahmed/Documents/CASS'
const SCRATCH = args.scratch || '/tmp/premerge-tmp'
const BRANCH = args.branch
const BASE = args.base
const PR = args.pr
const PLAN = args.planPath
const DEFERRED = args.deferredPath || null
const LABEL = args.label

const CONTEXT = `
Project: CASS v2, a multi-tenant Laravel 13 + Filament 5 + Livewire 4 + Tailwind 4 app. Repository ${REPO} (use the Bash tool with Git Bash paths; the shell cwd resets between calls, so start every command with cd ${REPO}). Branch under review: ${BRANCH}, which implements ${LABEL} on top of main (${BASE}); its pull request is #${PR} and CI is green. The plan it implements is ${PLAN} (read its header, its file-structure map and the task whose code you are looking at; the plan was adversarially reviewed before implementation, and the implementers were reviewed per task by a single reviewer, so the remaining defects are the subtle ones: cross-task interactions, tenant boundaries, races, things the per-task reviewer had no context for). Project rules: ${REPO}/CLAUDE.md. Spec: docs/superpowers/specs/2026-09-10-cass-v2-design.md.

Conventions: declare(strict_types=1); business logic in app/Actions; Filament resources split into Schemas/ and Tables/; guarded transitions via forceFill with exact fillable lists (preventSilentlyDiscardingAttributes is on outside production); a policy for every model with every ability Filament may call (Filament treats a MISSING policy method as allowed); cross-tenant and cross-conference negatives for every panel page and action; three panels: admin (/admin), organizer (/org, tenant Organization by slug), reviewer (/review); Conference and Submission route keys are ulid in panels; public routes bind explicitly and do not use AuthenticateSession; App\\Support\\ClientIp for IP-keyed limiters; tokens are 32 random bytes hex, stored SHA-256; templated mail via SendTemplatedEmail/TemplatedMail with placeholders escaped before Markdown and every message logged to email_logs; queue worker and scheduler under supervisord in one container; migrations never at boot; tests: Pest 5 with bootOrganizerPanel()/bootReviewerPanel()/withoutTenant() helpers, verify by exit code, browser suite is CI-only.
${DEFERRED ? `\nThe per-task reviewers deferred minor findings; they are listed one per line in ${DEFERRED}. Read them: they are leads, not verdicts.` : ''}
`

const READ_ONLY = `READ-ONLY: do not edit, create or delete files in the repository, do not commit, do not push, do not switch branches, do not run composer/npm install. Read the diff with cd ${REPO} && git diff ${BASE}...${BRANCH} --stat and git diff ${BASE}...${BRANCH} -- <path> per file (the whole diff is large: work file by file, and read whole files when the diff is not enough). You may run the suite or single test files (php artisan test --filter=...), php artisan route:list, and write throwaway scripts under ${SCRATCH}/.`

const FINDINGS_SCHEMA = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          id: { type: 'string', description: 'unique id with lens prefix, e.g. sec-01' },
          severity: { type: 'string', enum: ['critical', 'important', 'minor'] },
          file: { type: 'string', description: 'repo-relative path with forward slashes' },
          line: { type: 'integer' },
          title: { type: 'string', description: 'ONE line' },
          problem: { type: 'string', description: 'ONE LINE: what is wrong and the concrete failure or exploit scenario' },
          evidence: { type: 'string', description: 'ONE LINE: the code you read (file:line) proving it' },
          fix: { type: 'string', description: 'ONE LINE or short code, concrete; name the test to add when behaviour changes' },
        },
        required: ['id', 'severity', 'file', 'title', 'problem', 'evidence', 'fix'],
      },
    },
    summary: { type: 'string', description: 'ONE LINE' },
  },
  required: ['findings', 'summary'],
}

const VERDICTS_SCHEMA = {
  type: 'object',
  properties: {
    verdicts: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          id: { type: 'string' },
          verdict: { type: 'string', enum: ['confirmed', 'refuted', 'modified'] },
          reason: { type: 'string', description: 'ONE LINE: the evidence you checked yourself' },
          fix: { type: 'string', description: 'for confirmed: the fix (may repeat); for modified: the corrected fix; for refuted: empty' },
        },
        required: ['id', 'verdict', 'reason', 'fix'],
      },
    },
  },
  required: ['verdicts'],
}

const FIX_SCHEMA = {
  type: 'object',
  properties: {
    headSha: { type: 'string' },
    applied: { type: 'array', items: { type: 'string' }, description: 'one line per finding: id -> what changed, file:line' },
    notApplied: { type: 'array', items: { type: 'string' }, description: 'one line per finding: id -> reason' },
    gates: { type: 'string', description: 'ONE line: test/phpstan/pint rc values and test count' },
    ci: { type: 'string', description: 'ONE line: CI result per job' },
  },
  required: ['headSha', 'applied', 'notApplied', 'gates', 'ci'],
}

const LENSES = [
  {
    key: 'sec',
    title: 'Security, tenant isolation and abuse resistance',
    brief: `Hunt for exploitable gaps across the WHOLE branch, with a concrete scenario for each: any panel page, resource, relation manager, action, bulk action, export, download, notification or command reachable with a foreign organization's or foreign conference's record (by ULID in the URL, by record ids in a bulk action payload, through a relation manager mounted on a foreign parent, through a Select relationship query that is not tenant-scoped, through global search); policy methods Filament can call that are missing (missing = allowed) or that walk nullable relations without a guard; the panels' auth boundaries (canAccessPanel, verified email, MFA, a reviewer opening another conference's queue or a submission outside their pool/assignments, a removed reviewer, blind review leaking author identity in any view, export, notification or mail); tokens (entropy, hashing, single use, expiry, rotation, revoke, accept while logged in as someone else, rate limits keyed on ClientIp, enumeration); role invariants; review integrity; idempotency under concurrent runs; mass assignment and fillable lists; XSS in Blade and mail (placeholders escaped before Markdown; user-typed labels in validation rules or HTML); header injection; anything logged or stored in plaintext that should be hashed or redacted.`,
  },
  {
    key: 'cor',
    title: 'Correctness, cross-task interaction and runtime behaviour',
    brief: `Hunt for real bugs the per-task reviewers could not see because they looked at one task at a time: state transitions that two tasks drive differently, hooks one task expects another to call (and does not), queries that are correct on SQLite but wrong or slow on MySQL 8.4, N+1 on list pages, transactions and lockForUpdate scope, jobs and notifications dispatched inside transactions without afterCommit, scheduler commands (overlap, timezone, idempotency), race conditions, off-by-one and boundary conditions on deadlines and thresholds, timezone handling (stored UTC, displayed in the conference timezone via copy()), null handling on soft-deleted parents, error paths that 500 instead of denying or reporting, Livewire components with unlocked public properties, Filament actions whose visible() and authorization disagree, language keys used but not defined, migration ordering and down() correctness, activity-log entries promised by the spec but missing, and anything in the diff that contradicts the plan's stated behaviour in a way that matters to a user.`,
  },
  {
    key: 'tst',
    title: 'Test validity and coverage',
    brief: `For the tests the branch adds: which ones would still pass if the feature broke (vacuous assertions, assertSee on a string present for another reason, fakes that mask the path under test, Notification::fake hiding email_logs behaviour, tests that never exercise the guard they name)? Which depend on wall-clock time, ordering without ORDER BY, SQLite-only behaviour, or leak state (rate limiter cache, Filament panel/tenant, Carbon::setTestNow without reset)? Then coverage: list the spec-required behaviours of ${LABEL} with no real test (cross-tenant and cross-conference negatives for EVERY new page/action/bulk action/export/download, token single use and expiry, deadline boundaries on both sides, transitions and their blockers, email template key and logging for every send). Run the relevant test files yourself to confirm they pass at HEAD. Each missing test is a finding with the test code to add and the file it belongs in; each invalid test is a finding with the corrected assertion.`,
  },
]

phase('Review')
const results = await pipeline(
  LENSES,
  (lens) => agent(
    `${CONTEXT}\n\n${READ_ONLY}\n\nYou are an expert reviewer for ONE lens: ${lens.title}.\n\n${lens.brief}\n\nCover the entire diff (git diff ${BASE}...${BRANCH} --stat first, then every changed file that is in your lens's territory; do not sample). Report only real problems with concrete evidence and a concrete fix. Do not report style. Use ids prefixed "${lens.key}-". Severity: critical = exploitable or would fail in production; important = wrong behaviour, missing required coverage, or a defect a user would hit; minor = worth fixing before merge if cheap. REPORT FORMAT: parsed as JSON; every string ONE line, no raw newlines or tabs; code in fix must be JSON-escaped.`,
    { label: `review:${lens.key}`, phase: 'Review', schema: FINDINGS_SCHEMA, model: 'opus', effort: 'max' },
  ),
  (review, lens) => {
    if (!review || !review.findings || review.findings.length === 0) {
      return { lens: lens.key, findings: [], verdicts: [], summary: review ? review.summary : 'reviewer failed' }
    }
    return agent(
      `${CONTEXT}\n\n${READ_ONLY}\n\nYou are a skeptical verifier. Another reviewer (lens: ${lens.title}) reported the findings below against branch ${BRANCH}. For EACH finding, independently check the evidence yourself: open the cited file at HEAD, read the surrounding code, run the relevant test if that settles it. Try to REFUTE it. Verdicts: "confirmed" only if you reproduced the problem from the evidence; "modified" if the problem is real but the fix is wrong, incomplete or would break something else (give the corrected fix); "refuted" if the code is actually correct, the concern is already handled elsewhere, or it is taste. Default to refuted when you cannot find evidence. REPORT FORMAT: parsed as JSON; reason is ONE line.\n\nFindings:\n${JSON.stringify(review.findings, null, 2)}`,
      { label: `verify:${lens.key}`, phase: 'Verify', schema: VERDICTS_SCHEMA, model: 'opus', effort: 'high' },
    ).then((v) => ({ lens: lens.key, findings: review.findings, verdicts: v ? v.verdicts : [], summary: review.summary }))
  },
)

const valid = results.filter(Boolean)
const accepted = []
const refuted = []
for (const r of valid) {
  const byId = new Map(r.findings.map((f) => [f.id, f]))
  for (const v of r.verdicts) {
    const f = byId.get(v.id)
    if (!f) continue
    if (v.verdict === 'refuted') refuted.push({ id: f.id, title: f.title, reason: v.reason })
    else accepted.push({ ...f, verdict: v.verdict, verifier_reason: v.reason, fix: v.fix && v.fix.length > 0 ? v.fix : f.fix })
  }
}
log(`accepted ${accepted.length} findings (${accepted.filter((f) => f.severity === 'critical').length} critical, ${accepted.filter((f) => f.severity === 'important').length} important), refuted ${refuted.length}`)

phase('Fix')
let fix = null
if (accepted.length > 0) {
  fix = await agent(
    `${CONTEXT}\n\nYou are the fixer for the pre-merge review of ${BRANCH}. Work in ${REPO}. Confirm git branch --show-current is ${BRANCH} and git status --short is empty; otherwise report with headSha "BLOCKED" and explain.\n\nApply every verified finding below (critical and important are mandatory; minor ones when the fix is small and safe). For each behavioural fix, write or adjust the test first and see it fail (rc non-zero), then fix at the cause; keep every change minimal and inside the plan's scope (no refactors, no new features). If a fix is wrong or would break something, do not apply it and say why in notApplied. Rules: composer only via the composer.phar path in CLAUDE.md; php - stdin does not work (write script files under ${SCRATCH}/); every new PHP file starts with declare(strict_types=1); never run anything against production; never run Playwright locally; never stage .env, database/database.sqlite, public/build.\n\nGates: php artisan test > /tmp/t-premerge.log 2>&1; echo "rc=$?"; tail -3 /tmp/t-premerge.log (rc=0); ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G (rc=0); ./vendor/bin/pint --test (rc=0). Then ONE commit: "fix: pre-merge review of ${LABEL}" with a body listing each applied finding in one line and the trailer Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>; stage only the files you changed. Then git push origin ${BRANCH} and gh pr checks ${PR} --watch --interval 30 for up to 35 minutes; if a job fails, gh run view <id> --log-failed, fix at the cause, commit, push, watch again (at most two rounds). Do NOT merge the pull request.\n\nREPORT FORMAT: parsed as JSON; every string ONE line.\n\nFindings (JSON):\n${JSON.stringify(accepted, null, 2)}`,
    { label: 'fix:premerge', phase: 'Fix', schema: FIX_SCHEMA, model: 'opus', effort: 'max' },
  )
} else {
  log('no accepted findings; nothing to fix')
}

return {
  lensSummaries: valid.map((r) => ({ lens: r.lens, found: r.findings.length, summary: r.summary })),
  accepted: accepted.map((f) => ({ id: f.id, severity: f.severity, file: f.file, title: f.title, verdict: f.verdict })),
  refuted: refuted.map((r) => ({ id: r.id, title: r.title })),
  fix,
}
