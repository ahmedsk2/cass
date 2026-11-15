#!/usr/bin/env node
// Checks the pointers in the repository's living documents and code comments; exits 1 if any is broken.
//
//   node tools/docs/check-pointers.mjs <repository root>
//
// Records (dated plans, docs/reviews) have only their markdown links checked. Everything else:
// links and #anchors (GitHub's heading slugs), backticked paths, runbook and backlog titles cited by
// name (possessive, comma and chained forms, wrapped across lines, in markdown and in code), the
// runbook's own (see "X"), (see **X**) and under "X", "step N" of a runbook section, decision
// numbers, HANDOVER row ids, no backlog.md:<line> citation, and in the runbook's quick procedures
// every command appearing verbatim in the section its step links. Node built-ins only: CI's
// `scripts` job runs it with no npm install. app/Actions/Legacy/ and Legacy/ are never read.
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative, resolve, sep, extname } from 'node:path';

const root = resolve(process.argv[2] ?? '.');
const posix = (p) => p.split(sep).join('/');
const read = (p) => readFileSync(join(root, p), 'utf8');

const SKIP_DIRS = new Set(['node_modules', 'vendor', 'Legacy', '.git', 'storage', 'build', 'envato', 'worktrees']);
const SKIP_FILES = new Set(['legacy-review.md', 'CLAUDE.local.md']);
// Never read: the legacy import's code, and this checker's own test, whose fixtures break every rule.
const DENIED = ['app/Actions/Legacy/', 'tools/docs/check-pointers.test.mjs'];
const CODE_EXT = new Set(['.php', '.sh', '.mjs', '.js', '.yml', '.yaml']);
const CODE_ROOTS = ['app', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes', 'tests', 'tools', 'docker', '.github', '.claude'];

function walk(dir, out) {
    for (const name of readdirSync(join(root, dir))) {
        if (SKIP_DIRS.has(name) || SKIP_FILES.has(name)) continue;
        const p = dir === '.' ? name : `${dir}/${name}`;
        if (DENIED.some((d) => p.startsWith(d))) continue;
        const st = statSync(join(root, p));
        if (st.isDirectory()) walk(p, out);
        else out.push(p);
    }
    return out;
}

const all = walk('.', []);
const mdFiles = all.filter((p) => p.endsWith('.md'));
const codeFiles = all.filter((p) => CODE_ROOTS.some((r) => p.startsWith(r + '/')) && CODE_EXT.has(extname(p)));
const isRecord = (p) => /^docs\/superpowers\/plans\/20\d\d-/.test(p) || p.startsWith('docs/reviews/');

// Markdown prose: lines outside code fences (others blanked, so line numbers hold).
function prose(text) {
    let fence = null;
    return text.split('\n').map((line) => {
        const m = line.match(/^\s*(```+|~~~+)/);
        if (m) { fence = fence === null ? m[1] : (line.trim().startsWith(fence) ? null : fence); return ''; }
        return fence === null ? line : '';
    });
}

// Code: strip comment markers, keep string text, so wrapped comment citations join up.
function codeText(text) {
    return text.split('\n').map((l) => l.replace(/^\s*(\/\*\*|\*\/|\/\/+|#+|\*)\s?/, ''));
}

// Paragraphs: runs of non-empty lines joined by spaces, with each character's line number.
function paragraphs(lines) {
    const out = [];
    let text = '', map = [];
    const flush = () => { if (text) out.push({ text, map }); text = ''; map = []; };
    lines.forEach((line, i) => {
        if (line.trim() === '') { flush(); return; }
        if (text) { text += ' '; map.push(i + 1); }
        for (const _ of line) map.push(i + 1);
        text += line;
    });
    flush();
    return out;
}

function slug(heading) {
    const text = heading
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/`([^`]*)`/g, '$1')
        .replace(/(\*\*|__|\*)/g, '').replace(/<[^>]+>/g, '');
    return text.trim().toLowerCase().replace(/[^\p{L}\p{M}\p{N}\p{Pc} -]/gu, '').replace(/ /g, '-');
}

const docCache = new Map();
function doc(file) {
    if (!docCache.has(file)) {
        const raw = read(file).split('\n');
        const lines = prose(raw.join('\n'));
        const heads = [], slugs = new Map(), seen = new Map();
        lines.forEach((line, i) => {
            const m = line.match(/^(#{1,6})\s+(.*?)\s*#*\s*$/);
            if (!m) return;
            const s0 = slug(m[2]), n = seen.get(s0) ?? 0;
            const s = n === 0 ? s0 : `${s0}-${n}`;
            seen.set(s0, n + 1);
            heads.push({ level: m[1].length, title: m[2].replace(/`/g, '').replace(/\*\*/g, ''), slug: s, line: i });
        });
        heads.forEach((h, k) => {
            const next = heads.slice(k + 1).find((o) => o.level <= h.level);
            h.end = next ? next.line : lines.length;
            h.text = lines.slice(h.line + 1, h.end).join('\n');
            h.raw = raw.slice(h.line + 1, h.end).join('\n');
            h.steps = (h.text.match(/^\d+\. /gm) ?? []).length;
            slugs.set(h.slug, h);
        });
        for (const line of lines) for (const m of line.matchAll(/<a\s+(?:id|name)="([^"]+)"/g)) slugs.set(m[1], null);
        docCache.set(file, { lines, heads, slugs });
    }
    return docCache.get(file);
}

const problems = [];
const report = (file, n, what) => problems.push(`${file}:${n}: ${what}`);

const RUNBOOK = 'docs/runbooks/deploy-production.md';
const BACKLOG = 'docs/superpowers/plans/backlog.md';
const rb = doc(RUNBOOK);
const labels = new Set(rb.lines.flatMap((l) => { const m = l.match(/^\s*- \*\*([^*]+?)[.:]?\*\*/); return m ? [m[1]] : []; }));
const findTitle = (t) => rb.heads.find((h) => h.title === t || [' — ', ' (', ': ', ', '].some((s) => h.title.startsWith(t + s)));

// Backlog names: table areas and items, or headings (whole and without the parenthesis) before the conversion.
const backlogNames = new Set();
for (const line of doc(BACKLOG).lines) {
    const c = line.split(/(?<!\\)\|/).map((x) => x.trim());
    if (c.length >= 7 && c[1] !== 'Item' && !/^-+$/.test(c[1])) { backlogNames.add(c[1].replace(/\*\*|`/g, '')); backlogNames.add(c[2]); }
}
for (const h of doc(BACKLOG).heads) { backlogNames.add(h.title); backlogNames.add(h.title.replace(/\s*\(.*\)\s*$/, '')); }
for (const line of doc(BACKLOG).lines) { const m = line.match(/^- \*\*([^*]+?)\.?\*\*/); if (m) backlogNames.add(m[1].replace(/`/g, '')); }

const decisions = new Set(readdirSync(join(root, 'docs/decisions')).map((f) => f.slice(0, 4)));
const handoverIds = new Set([...read('docs/HANDOVER.md').matchAll(/^\| ([A-D]\d+) \|/gm)].map((m) => m[1]));
// Named on purpose although git does not hold them: runtime files, a directory the runbook says
// does not exist, a script the backlog proposes, and a test's fake plan path.
const KNOWN_ABSENT = new Set(['.claude/deploy-unlocked', 'database/database.sqlite', 'resources/images/',
    'tools/backup/restore-drill.sh', 'docs/test/plan-under-review.md']);
const pathPrefix = /^(app|bootstrap|config|database|docker|docs|lang|resources|routes|scripts|tests|tools|\.github|\.claude)\//;
const titles = (chain) => [...chain.matchAll(/"([^"]+)"|\*\*([^*]+)\*\*/g)].map((m) => (m[1] ?? m[2]).replace(/\s+/g, ' '));
const CHAIN = String.raw`((?:"[^"]+"|\*\*[^*]+\*\*)(?:\s*(?:,|and|or)\s+(?:"[^"]+"|\*\*[^*]+\*\*))*)`;
const RUNBOOK_CITE = new RegExp(String.raw`\brunbook(?:'s)?,?(?:\s+section)?\s+` + CHAIN, 'g');
const BACKLOG_CITE = new RegExp(String.raw`\bbacklog(?:\.md\`?)?(?:'s)?,?\s+\(?((?:"[^"]+")(?:\s*(?:,|and|or)\s+"[^"]+")*)`, 'g');
const BACKLOG_AREA = /backlog\.md`\s+\(([A-Z][A-Za-z ]+)\)/g;
const STEP_CITE = /(?:"([^"]+)"|\*\*([^*]+)\*\*)\s+steps?\s+(\d+)/g;

function checkCitations(file, text, at, inRunbook) {
    for (const m of text.matchAll(RUNBOOK_CITE)) {
        for (const t of titles(m[1])) if (!findTitle(t)) report(file, at(m.index), `runbook title not found: "${t}"`);
    }
    if (inRunbook) {
        for (const m of text.matchAll(/(?:(?:\(|\bsee |\bunder |\bin )\*\*([A-Z`][^*]+)\*\*|(?:\bsee |\bunder )"([A-Z`][^"]+)")(?=[),;. ]| step)/g)) {
            const t = m[1] ?? m[2];
            if (!findTitle(t) && !labels.has(t)) report(file, at(m.index), `runbook cross-reference not found: ${t}`);
        }
    }
    for (const m of text.matchAll(STEP_CITE)) {
        const t = m[1] ?? m[2], h = findTitle(t);
        if (h && h.steps < Number(m[3])) report(file, at(m.index), `"${t}" has no step ${m[3]}`);
    }
    for (const m of text.matchAll(BACKLOG_CITE)) {
        for (const t of titles(m[1])) if (!backlogNames.has(t)) report(file, at(m.index), `backlog area or item not found: "${t}"`);
    }
    for (const m of text.matchAll(BACKLOG_AREA)) if (!backlogNames.has(m[1])) report(file, at(m.index), `backlog area not found: (${m[1]})`);
    for (const m of text.matchAll(/\bdecisions? (\d{4})\b/g)) if (!decisions.has(m[1])) report(file, at(m.index), `decision not found: ${m[1]}`);
    for (const m of text.matchAll(/docs\/decisions\/(\d{4})-[\w-]+\.md/g)) if (!existsSync(join(root, m[0]))) report(file, at(m.index), `missing decision file: ${m[0]}`);
    for (const m of text.matchAll(/HANDOVER(?:\.md)?`?(?:'s)?,? ([A-D]\d{1,2})\b/g)) if (!handoverIds.has(m[1])) report(file, at(m.index), `HANDOVER row not found: ${m[1]}`);
    for (const m of text.matchAll(/backlog\.md:\d/g)) report(file, at(m.index), 'a backlog line number: cite the area or the item instead');
}

for (const file of mdFiles) {
    const d = doc(file), record = isRecord(file);
    d.lines.forEach((line, i) => {
        const noCode = line.replace(/`[^`]*`/g, '``');
        for (const m of noCode.matchAll(/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g)) {
            const target = m[1];
            if (/^[a-z]+:/i.test(target) || target.startsWith('{{')) continue;
            const [path, anchor] = target.split('#');
            const dest = path === '' ? file : posix(relative(root, resolve(join(root, dirname(file)), path)));
            if (!existsSync(join(root, dest))) { report(file, i + 1, `link to a missing file: ${target}`); continue; }
            if (anchor !== undefined && dest.endsWith('.md') && !doc(dest).slugs.has(anchor)) report(file, i + 1, `link to a missing anchor: ${target}`);
        }
        if (record) return;
        for (const m of line.matchAll(/`([^`\s]+)`/g)) {
            const p = m[1].replace(/:\d+(-\d+)?$/, '').replace(/[.,;]$/, '');
            if (!pathPrefix.test(p) || /[*<>{}$…]/.test(p) || KNOWN_ABSENT.has(p)) continue;
            // A path cited "at commit <sha>" names that commit's tree, not today's.
            if (/^\s*at commit\b/.test(line.slice(m.index + m[0].length))) continue;
            if (!existsSync(join(root, p))) report(file, i + 1, `missing path: ${m[1]}`);
        }
    });
    if (record) continue;
    for (const p of paragraphs(d.lines)) checkCitations(file, p.text, (k) => p.map[k], file === RUNBOOK);
}

for (const file of codeFiles) {
    for (const p of paragraphs(codeText(read(file)))) {
        checkCitations(file, p.text, (k) => p.map[k], false);
        for (const m of p.text.matchAll(/\bdocs\/[\w/.-]+\.md\b/g)) {
            if (!existsSync(join(root, m[0])) && !KNOWN_ABSENT.has(m[0])) report(file, p.map[m.index], `missing path: ${m[0]}`);
        }
    }
}

// Quick procedures: a command in a step appears verbatim in a section that step links,
// or anywhere in the runbook outside the quick procedures when the step links none.
const quick = rb.heads.filter((h) => /^Q\d+\. /.test(h.title));
if (quick.length > 0) {
    const first = quick[0].line, last = quick[quick.length - 1].end;
    const outside = read(RUNBOOK).split('\n').filter((_, i) => i < first || i >= last).join('\n');
    for (const q of quick) {
        const steps = q.text.split(/\n(?=- )/);
        for (const step of steps) {
            const anchors = [...step.matchAll(/\]\(#([^)]+)\)/g)].map((m) => rb.slugs.get(m[1])).filter(Boolean);
            for (const m of step.matchAll(/`((?:sudo|ssh|gh|git|curl|C=|APP=|DB=|NEWEST=|docker)\b[^`]*)`/g)) {
                const ok = anchors.length > 0 ? anchors.some((h) => h.raw.includes(m[1])) : outside.includes(m[1]);
                if (!ok) report(RUNBOOK, q.line + 1, `${q.title}: command not verbatim in its linked section: ${m[1]}`);
            }
        }
    }
}

for (const p of problems) console.log(p);
console.log(`${problems.length} broken pointer(s) in ${mdFiles.length} documents and ${codeFiles.length} code files`);
process.exit(problems.length === 0 ? 0 : 1);
