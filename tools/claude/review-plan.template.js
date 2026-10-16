// Workflow tool script: the six-lens adversarial plan review, as run on Plan 3 (the lens briefs,
// CONTEXT, PLAN path and section map are plan-specific: adapt them for the plan under review).

export const meta = {
  name: 'review-plan-3',
  description: 'Adversarially review CASS Plan 3 (submissions) through six lenses, apply confirmed fixes, critic pass',
  phases: [
    { title: 'Review', detail: 'six independent lens reviewers over the plan, the real code and vendor source', model: 'opus' },
    { title: 'Verify', detail: 'one skeptic per lens tries to refute each finding', model: 'opus' },
    { title: 'Edit', detail: 'single editor applies confirmed fixes to the plan file', model: 'opus' },
    { title: 'Critic', detail: 'structural and consistency check of the edited plan', model: 'opus' },
  ],
}

const PLAN = 'docs/superpowers/plans/2026-09-11-plan-3-submissions.md'

const CONTEXT = `
Project: CASS v2, a multi-tenant Laravel 13.31 + Filament 5.8.1 + Livewire 4.4.4 + Tailwind 4.3 app. Repository: /c/Users/ahmed/Documents/CASS (use the Bash tool with Git Bash paths). Current branch: plan-3-submissions, created from main at commit 4961530 (Plans 1 and 2 merged and deployed; 232 tests green). Installed: Pest 5.1.4 (laravel + livewire plugins), Larastan level 6, Pint (strict types), spatie/laravel-activitylog 5.1, chillerlan/php-qrcode 5.0.5, barryvdh/laravel-dompdf 3.1.x, openspout/openspout 4.32.0 (transitive via filament/actions), symfony/html-sanitizer. NOT installed: pestphp/pest-plugin-browser (Plan 3 Task 13 adds it) — to check its API read its source on GitHub (https://github.com/pestphp/pest-plugin-browser) with WebFetch or state that you could not verify. PHP 8.4.23 on Windows without sodium, exif or sockets extensions; php -  (stdin scripts) does not work here.

Documents:
- The plan under review: ${PLAN} (11,677 lines). Section map: header and environment facts L1-48; file structure L49-198; "Facts verified in vendor" L199-229; Task 1 L230-2145 (schema, enums, models, factories, policies); Task 2 L2146-2809 (WordCounter, tokens, reference numbers, MIME sniffing); Task 3 L2810-4146 (TemplatedMail, platform default templates, email_logs pipeline, NewSubmissionNotice); Task 4 L4147-4723 (private file storage, signed /files/{ulid}); Task 5 L4724-5934 (SaveSubmissionDraft, SubmitAbstract, UpdateSubmission, WithdrawSubmission); Task 6 L5935-7208 (public submit form Livewire component: route, structure, word count, authors, custom fields); Task 7 L7209-8061 (files, honeypot, throttle, Turnstile, CTA href); Task 8 L8062-8658 (status page /s/{token} with edit and withdraw); Task 9 L8659-9708 (organizer SubmissionResource: list, view, withdraw, resend, CSV export); Task 10 L9709-10315 (per-conference email template editor page); Task 11 L10316-10720 (admin EmailLogResource, admin conference counts); Task 12 L10721-10991 (language files, .env.example, runbook sections); Task 13 L10992-11308 (browser test with pest-plugin-browser + Playwright, CI job, php.ini sockets); Task 14 L11309-11400 (MySQL run, backlog, final gate, PR); trailing sections L11401-11677 (deferrals, verification, self-review).
- Approved design spec: docs/superpowers/specs/2026-09-10-cass-v2-design.md (Plan 3 covers 5.3, 5.9, the /submit, /s/{token} and /files/{ulid} rows of 6, 8, 9, 10, 12 and the submission parts of 3, 4, 7, 13).
- Project rules: CLAUDE.md. Format and convention reference: docs/superpowers/plans/2026-09-10-plan-2-conferences.md (the previous, already-implemented plan). Deferred items: docs/superpowers/plans/backlog.md.
- The real code Plans 1 and 2 produced (authoritative over any plan text): app/, bootstrap/app.php, routes/web.php, config/, database/, resources/, lang/ (if present), tests/, Dockerfile, docker/, docker-compose.production.yml, .github/workflows/ci.yml, phpunit.xml, vite.config.js, package.json. Vendor source is in vendor/.

Existing conventions the plan must follow: declare(strict_types=1) everywhere; business logic in app/Actions; Filament resources split into Schemas/ and Tables/ classes; guarded state transitions via forceFill (status and similar are not fillable, and Model::preventSilentlyDiscardingAttributes() throws outside production); a policy for every tenant-owned model with every method Filament may call; a cross-tenant negative test for every panel resource, page and action; tests use "use function Pest\\Livewire\\livewire;" and the helpers bootOrganizerPanel($org) / withoutTenant() from tests/Pest.php; SecurityHeaders is global; trusted proxies come from TRUSTED_PROXIES; client IP for rate limits comes from App\\Support\\ClientIp; markdown mail uses secured encoding; the organizer panel id is 'organizer' at path /org with tenant Organization (slugAttribute 'slug'); the admin panel is 'admin' at /admin; Conference route key is 'ulid' in panels and public routes bind {conference:slug} explicitly with scopeBindings(); public routes do not use AuthenticateSession (see the comment in routes/web.php); the production image runs nginx and php-fpm as user 'app' with pm.max_children=4 and a 768M memory limit, nginx serves any URI ending in a static extension from disk with a fallthrough to index.php, and the named volume cass-storage is mounted at /var/www/html/storage/app; migrations are never run at boot; a supervisord queue worker and scheduler run in the container.

Owner decisions already taken (do not raise these as findings): email templates are edited on a resource page backed by an array table rather than a relation manager; SubmissionResource sets $isScopedToTenant = false and scopes its Eloquent query by hand because Submission has no organization_id (the security lens must still verify that every query, action, page and policy in the plan is scoped correctly); openspout is promoted to a direct dependency; the browser suite lives in its own phpunit.browser.xml and tests/Browser is excluded from phpunit.xml; the reviewer and decision template keys get defaults and an editor in this plan but are not sent.
`

const READ_ONLY = `This is READ-ONLY review work: do not edit, create or delete any file, do not run composer/npm install or require, do not commit, do not start Docker containers. You may read any file, grep vendor, run php artisan route:list or write a throwaway PHP script to the session scratchpad (C:/Users/ahmed/AppData/Local/Temp/claude/C--Users-ahmed-Documents-CASS/34d09af2-98e6-4f66-9155-27bca70b7eb7/scratchpad/plan3/) for read-only checks, and use WebFetch for package source that is not installed.`

const FINDINGS_SCHEMA = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          id: { type: 'string', description: 'unique id with lens prefix, e.g. api-01' },
          task: { type: 'string', description: 'Task number or section name' },
          line: { type: 'integer', description: 'plan line number where the problem appears' },
          severity: { type: 'string', enum: ['critical', 'important', 'minor'] },
          title: { type: 'string', description: 'one line' },
          problem: { type: 'string', description: 'ONE LINE (no newlines): what is wrong and what would happen if an implementer followed the plan as written' },
          evidence: { type: 'string', description: 'ONE LINE: exact plan text and/or vendor file:line or code quote proving it' },
          fix: { type: 'string', description: 'concrete replacement text or code for the plan; may be multi-line but must be valid inside a JSON string (escape quotes and newlines)' },
        },
        required: ['id', 'task', 'line', 'severity', 'title', 'problem', 'evidence', 'fix'],
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
          fix: { type: 'string', description: 'for confirmed: the fix to apply (may repeat the original); for modified: the corrected fix; for refuted: empty' },
        },
        required: ['id', 'verdict', 'reason', 'fix'],
      },
    },
  },
  required: ['verdicts'],
}

const LENSES = [
  {
    key: 'api',
    title: 'Framework and package API correctness',
    brief: `Verify that every framework and package API used in the plan's code blocks exists with the exact name, signature, namespace and behaviour the plan assumes, in the INSTALLED versions: Filament 5.8.1 (resources, resource pages with custom routes, array-backed tables via Table::records() and ArrayRecord, infolists, actions, header actions, bulk actions, file download responses, tenancy internals such as $isScopedToTenant, testing helpers such as callAction, callTableAction, mountAction, fillForm, assertHasFormErrors, assertCanSeeTableRecords, assertCanNotSeeTableRecords, assertTableActionVisible), Livewire 4.4.4 (temporary file uploads WithFileUploads, wire:model.live/.blur/.debounce, validation attributes, redirects, flash, locked properties, component testing with livewire()->set()->call()->assertRedirect(), file upload testing with UploadedFile::fake()), Laravel 13.31 (routing and scopeBindings, signed routes and URL::temporarySignedRoute, ValidatesWhenResolved, validation rules including File::types()/mimetypes, Storage streaming downloads, Mail events MessageSending/MessageSent and their public properties in Symfony Mailer 8, queued mailables and Mailable::envelope()/content(), Notification channels, RateLimiter, Str helpers, Carbon, Http::fake sequences, lockForUpdate), openspout 4.32 (Writer\\CSV\\Writer, Row::fromValues, openToBrowser vs openToFile, streaming), symfony/html-sanitizer and the mail Markdown renderer, Pest 5.1.4 functions and datasets, pest-plugin-browser 5 (visit(), fill/attach/click/assertSee, the phpunit config it needs, how it boots Playwright), Playwright npm package. Grep vendor/ for each class and method and quote file:line. Flag anything that would not compile, would throw, or behaves differently from what the plan's tests expect. Also check that every Blade component, view name, Filament icon enum and language key the plan references exists or is created by the plan.`,
  },
  {
    key: 'consistency',
    title: 'Cross-task consistency and ordering',
    brief: `Build an index of every symbol the plan defines (classes, methods and their signatures, enum cases, config keys, routes and route names, middleware aliases, migration columns and indexes, factory states, Blade views and partials, language keys, CSS variables, test helpers, phpunit config files, npm scripts, CI job names) with the task that defines it, then check every reference to it in every task: same name, same signature, same column, defined in the same or an EARLIER task. Flag references to things never defined, defined later than used, renamed between tasks, or that clash with the real code from Plans 1 and 2 (read app/, config/, routes/, resources/, tests/ to confirm current names: for example App\\Support\\ClientIp, App\\Support\\Branding\\OrganizationTheme, App\\Support\\Html\\RichText, the public layout component, ConferenceResource and its Schemas/Tables/Pages classes, ConferenceInfolist, ConferenceShortLink page, the organizer Dashboard, ConferencePolicy, OrganizationMember and notify_on_submission, User::roleIn, Organization::isApproved). Check that each task's Files list matches the files its steps actually create or modify, that the file-structure map at L49-198 matches the tasks, and that every commit step stages exactly the files the task touched. Also check the plan's stated expectation that a task never commits with a failing test: Task 3 admits it commits with one red test that Task 5 fixes — report that as a finding with a restructuring fix (make Task 3's test self-contained or move it to Task 5) because CI runs on every push and the project rule is that each task ends green.`,
  },
  {
    key: 'security',
    title: 'Security, tenant isolation and abuse resistance',
    brief: `Examine every route, Livewire component, action, policy, resource, page, mail template and view the plan adds for security flaws with a concrete exploit scenario for each: author token handling (entropy, hashing, comparison, where plaintext appears, token rotation on resend, tokens in logs or activity log or URLs that get logged by nginx or Cloudflare, referer leakage from the status page to external links), the status page and edit flow (can a token holder edit after the deadline, after withdrawal, a submission of another conference, or a submission whose conference is archived or organization suspended), the submit route (window checks, member preview, unpublished conference exposure, cross-organization slug binding), file handling (10 MB and count limits enforced before the file is stored, extension allow-list vs sniffed MIME, PDF polyglots, Livewire temporary upload validation and the livewire temp disk, content-addressed path traversal, original filename in Content-Disposition header injection, nosniff, signed URL lifetime and tampering, whether a signed URL for one file can be replayed for another, whether the organizer download is authorized by policy or only by signature, deletion of files on withdraw/edit), rate limiting keyed on App\\Support\\ClientIp for draft, submit, status lookup and Turnstile failures, honeypot and minimum-fill-time bypass, Turnstile verification (remoteip, token reuse, fail-open when keys are missing is intended but fail-open on HTTP error is not), the email pipeline (placeholder rendering and HTML escaping BEFORE Markdown, Markdown injection of links/images/raw HTML by organizers into templates, header injection via subject or author name, recipient validation, notification to opted-in members only, email_logs storing message bodies or tokens), XSS in Blade output of submission fields (title, abstract, author names, affiliations, custom field values, filenames) on the status page, the organizer resource and the emails, mass assignment and fillable lists versus preventSilentlyDiscardingAttributes, Filament tenancy scoping with $isScopedToTenant = false (every table query, record resolution, relation manager, action, bulk action, export, resend and the EmailTemplates page must be scoped to the current tenant; global scopes bypassed by unique/exists validation rules; route model binding outside the tenant scope; the admin panel's access), policy coverage for every model and ability including deleteAny/forceDelete/restore, CSV formula injection in the export, and spec section 9 requirements.`,
  },
  {
    key: 'tests',
    title: 'Test validity and coverage',
    brief: `For every test the plan writes: would it FAIL for the stated reason when run at the stated step (before the implementation), and PASS after it? Look for tests that pass vacuously, assert on the wrong thing, depend on wall-clock time without Carbon::setTestNow or travelTo, depend on SQLite versus MySQL behaviour (JSON columns, unique indexes with soft deletes, lockForUpdate on SQLite, case-insensitive collation, ordering without ORDER BY, whereJsonContains), leak state between tests (rate limiter cache, Storage::fake, Filament tenant, Http::fake, Mail::fake vs Queue::fake vs Notification::fake interactions — note that Mail::fake prevents MessageSending events so email_logs assertions need care), or use Pest, Livewire or Filament testing helpers incorrectly (Livewire file uploads in tests, calling Filament actions on a custom resource page, testing a signed route with URL::temporarySignedRoute and travel past expiry). Check the exact run commands and expected outputs. Check coverage against spec section 12 for the submission scope: draft, submit, edit, withdraw, deadline enforcement on both sides of the boundary, window closed and upcoming, unpublished conference 404, suspended organization 404, honeypot, rate limits, Turnstile success and failure, file size/count/type/MIME rejection, cross-conference and cross-tenant token and resource misuse, signed URL expiry and tampering, emails queued with the right template and logged, per-conference override beating the default, organization notice only to opted-in members, reference number sequencing, word counting edge cases, CSV export content, the admin email log, language keys present. List missing tests that matter, each with the test code to add and the task it belongs in. Also check the browser test (Task 13) is runnable as written in CI and correctly excluded locally.`,
  },
  {
    key: 'spec',
    title: 'Spec coverage and scope',
    brief: `Map every requirement in the design spec that falls in Plan 3's scope (5.3 author submission step by step, 5.9 emails including every template key, placeholder and the email_logs requirement, the /submit, /s/{token} and /files/{ulid} rows of section 6, 8 data storage and indexes and file paths, 9 security, 10 non-functional such as the 300 ms render budget, language files and timezone handling, 12 testing including the browser test, 13 brand) and every hand-off Plan 2 recorded for Plan 3 in docs/superpowers/plans/backlog.md (email templates, the CTA href, the /submit binding) to the task that implements it. Report every requirement with no implementing task, every place where the plan contradicts the spec, and every piece of scope creep that belongs to Plans 4-6 or to v2. Check the permissions table is honoured exactly (all organization members may view submissions and files and edit templates; authors act only through their token; platform admin sees everything read-only where the plan says so). Check the plan's own self-review section (L11511 onward) is accurate and that the "What this plan does NOT build" list is honest.`,
  },
  {
    key: 'ops',
    title: 'Build, CI, runtime and environment',
    brief: `Check everything that affects installing, building, CI and production: composer constraints resolve with the installed lock (openspout ^4 alongside filament/actions, pest-plugin-browser ^5 alongside Pest 5.1.4 and the laravel/livewire plugins — read its composer.json on GitHub), the php.ini sockets change on the Windows machine (correct line, restart needs, does composer install of the plugin really require ext-sockets, and what CI's shivammathur/setup-php needs in its extensions list), the npm playwright devDependency and Chromium install in CI and its cache, the new CI job's runtime and that the existing test, image and smoke jobs stay green (phpunit.xml exclusion of tests/Browser, phpunit.browser.xml, the Vite build including any new JS entry, the Docker image not needing new extensions, .dockerignore, that the smoke job's 64 KB POST still passes, and that MySQL and SQLite runs both pass), the queue worker and mail listener under supervisord (queued TemplatedMail, failed job handling writing email_logs status failed, the listener's registration in Laravel 13's event discovery), the private storage volume and permissions for user app (storage/app/private exists in the image, is writable, survives redeploys), Livewire temporary upload configuration in production (livewire.temporary_file_upload disk and rules, nginx client_max_body_size and php upload_max_filesize/post_max_size in docker/ for 10 MB files times max_files), memory for CSV export streaming within the 768M container, the runbook additions, .env.example completeness, and any step that would break on Windows line endings or paths.`,
  },
]

phase('Review')
const results = await pipeline(
  LENSES,
  (lens) => agent(
    `${CONTEXT}\n\n${READ_ONLY}\n\nYou are an expert reviewer for ONE lens: ${lens.title}.\n\n${lens.brief}\n\nRead the plan in full (it is long; read it in chunks with sed -n or the Read tool with offsets — do not skip tasks). Report only real problems that would cause an implementer following the plan to produce broken, insecure, untested or spec-violating software, or to get stuck. Do not report style preferences. Each finding needs concrete evidence (plan line text and, where relevant, vendor file:line) and a concrete fix written as replacement plan text or code. Use ids prefixed "${lens.key}-". Severity: critical = would not work or is exploitable; important = wrong behaviour, missing required coverage, or likely implementer failure; minor = worth fixing while editing. REPORT FORMAT: your final answer is parsed as JSON; keep title, problem, evidence and summary to ONE line each with no raw newlines or tabs; the fix field may contain code but every newline and double quote inside it must be JSON-escaped.`,
    { label: `review:${lens.key}`, phase: 'Review', schema: FINDINGS_SCHEMA, model: 'opus', effort: 'max' },
  ),
  (review, lens) => {
    if (!review || !review.findings || review.findings.length === 0) {
      return { lens: lens.key, findings: [], verdicts: [], summary: review ? review.summary : 'reviewer failed' }
    }
    return agent(
      `${CONTEXT}\n\n${READ_ONLY}\n\nYou are a skeptical verifier. Another reviewer (lens: ${lens.title}) reported the findings below against the plan. For EACH finding, independently check the evidence yourself: open the plan at the cited line, read the surrounding task, grep vendor or read the real code. Try to REFUTE it. Verdicts: "confirmed" only if you reproduced the problem from the evidence; "modified" if the problem is real but the proposed fix is wrong, incomplete or would break something else (give the corrected fix); "refuted" if the plan is actually correct, the concern is already handled elsewhere in the plan, or it is a matter of taste. Default to refuted when you cannot find evidence. For confirmed and modified, return the exact fix to apply to the plan. REPORT FORMAT: parsed as JSON; reason is ONE line; fix may contain code with JSON-escaped newlines and quotes.\n\nFindings:\n${JSON.stringify(review.findings, null, 2)}`,
      { label: `verify:${lens.key}`, phase: 'Verify', schema: VERDICTS_SCHEMA, model: 'opus', effort: 'max' },
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
  const verifiedIds = new Set(r.verdicts.map((v) => v.id))
  for (const f of r.findings) {
    if (!verifiedIds.has(f.id)) log(`unverified finding dropped from auto-apply: ${f.id} ${f.title}`)
  }
}
log(`accepted ${accepted.length} findings (${accepted.filter((f) => f.severity === 'critical').length} critical), refuted ${refuted.length}`)

phase('Edit')
const EDIT_SCHEMA = {
  type: 'object',
  properties: {
    applied: { type: 'array', items: { type: 'object', properties: { id: { type: 'string' }, change: { type: 'string', description: 'ONE line' } }, required: ['id', 'change'] } },
    skipped: { type: 'array', items: { type: 'object', properties: { id: { type: 'string' }, reason: { type: 'string', description: 'ONE line' } }, required: ['id', 'reason'] } },
    notes: { type: 'string', description: 'ONE line' },
  },
  required: ['applied', 'skipped', 'notes'],
}

const editPrompt = (items, extra) => `${CONTEXT}\n\nYou are the plan editor. Edit ONLY ${PLAN} (use the Edit tool for precise replacements; do not rewrite the whole file). Do not touch any other file, do not commit.\n\nApply every verified fix below. Several findings may overlap or touch the same code; apply each change once and keep the result coherent: when you change a symbol, class, method, route, column or test helper, update EVERY place the plan references it (search the file). Keep the plan's format intact: each task keeps its Files list, failing-test-first step with full code, the exact run command with the expected failure, full implementation code, the passing run, and the Pint + Larastan + commit step. No placeholders, no "similar to Task N", no TODOs. When a finding adds a missing test, add the full test code to the right task's test file and mention it in that task's run step expectations.\n\nAlso apply these owner decisions regardless of findings:\n1. No task may end with a commit while any test in the suite fails. If Task 3 still commits with a red test, restructure so the test lives in the task that makes it pass (or Task 3 ships the minimal method that makes it pass) and update the commit messages and the self-review accordingly.\n2. Every commit trailer must read "Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>".\n3. Keep the section headings (### Task N: ...) exactly as they are so the task-splitting tool keeps working.\n${extra || ''}\n\nFixes to apply (JSON):\n${JSON.stringify(items, null, 2)}\n\nReturn which ids you applied (one line describing each change) and which you skipped with the reason (skip only if the fix is already present or conflicts with another fix you applied; explain the conflict). REPORT FORMAT: parsed as JSON; every string ONE line.`

const edit1 = await agent(editPrompt(accepted), { label: 'edit:plan', phase: 'Edit', schema: EDIT_SCHEMA, model: 'opus', effort: 'max' })

phase('Critic')
const CRITIC_SCHEMA = {
  type: 'object',
  properties: {
    problems: { type: 'array', items: { type: 'object', properties: { task: { type: 'string' }, line: { type: 'integer' }, problem: { type: 'string', description: 'ONE line' }, fix: { type: 'string' } }, required: ['task', 'line', 'problem', 'fix'] } },
    verdict: { type: 'string', enum: ['ready', 'needs_fixes'] },
  },
  required: ['problems', 'verdict'],
}

const critic = await agent(
  `${CONTEXT}\n\n${READ_ONLY}\n\nYou are the completeness critic for ${PLAN}, which an editor just revised to apply review fixes. Read the whole plan and check: (1) all 14 tasks are intact and each still has Files, a failing test with full code, the run command with expected failure, full implementation code, the passing run and a Pint + Larastan + commit step, and no task commits with a failing test; (2) no dangling references were introduced (a symbol, method, route, column, view, language key, test helper or config key referenced but not defined in the same or an earlier task, or renamed in one place but not another); (3) no placeholders, TODOs, "similar to Task N", or half-applied edits (duplicated blocks, broken code fences, unbalanced braces in PHP blocks); (4) stated expected outputs are consistent with the tests actually written; (5) the self-review section still matches the tasks; (6) the task headings are still "### Task N: ..." for N = 1..14. Report only concrete problems with line numbers and exact fixes. Verdict "ready" only if there are none. REPORT FORMAT: parsed as JSON; problem is ONE line.\n\nFor reference, the editor's change log was:\n${JSON.stringify(edit1, null, 2)}`,
  { label: 'critic:plan', phase: 'Critic', schema: CRITIC_SCHEMA, model: 'opus', effort: 'max' },
)

let edit2 = null
if (critic && critic.verdict === 'needs_fixes' && critic.problems.length > 0) {
  edit2 = await agent(
    editPrompt(critic.problems.map((p, i) => ({ id: `critic-${i + 1}`, task: p.task, line: p.line, severity: 'important', title: p.problem.slice(0, 80), problem: p.problem, evidence: 'critic', fix: p.fix })), 'The owner decisions above were already applied in the previous edit pass; verify and leave them.'),
    { label: 'edit:critic-fixes', phase: 'Critic', schema: EDIT_SCHEMA, model: 'opus', effort: 'max' },
  )
}

return {
  lensSummaries: valid.map((r) => ({ lens: r.lens, found: r.findings.length, summary: r.summary })),
  accepted: accepted.map((f) => ({ id: f.id, severity: f.severity, task: f.task, title: f.title, verdict: f.verdict })),
  refutedCount: refuted.length,
  refuted: refuted.map((r) => ({ id: r.id, title: r.title })),
  edit1,
  critic,
  edit2,
}