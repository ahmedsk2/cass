# Security exceptions

A high or critical finding from `composer audit` or `npm audit --omit=dev` blocks a merge unless it has a row here: the advisory, why it is accepted for now, and the date by which it is reviewed again. Remove the row in the pull request that fixes the finding.

CI runs both audits in the `test` job of `.github/workflows/ci.yml`, right after the installs. The Composer step ignores low and medium advisories and abandoned packages; an advisory without a severity rating fails it like a high one until someone has looked at it. A row here is made effective differently for each tool:

- Composer: add the advisory to `config.audit.ignore` in `composer.json` with the same reason (`"audit": {"ignore": {"CVE-2026-00000": "why"}}`). `composer audit` then lists it as ignored and exits 0. The entry and the row leave together.
- npm: `npm audit` has no ignore list. When a fixed release of the transitive package exists, pin it with an `overrides` entry in `package.json`; that is a fix, so it needs no row. When none exists, the row records the decision and the owner approves the matching change to the step in `ci.yml`, a sensitive file, for the row's lifetime.

| Advisory | Package and version | Why accepted for now | Review by |
|---|---|---|---|
