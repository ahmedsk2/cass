# The repository is public so that CI runs

- **Context.** On 2026-10-16 GitHub Actions refused to start jobs on the private `ahmedsk2/cass` repository, and starting them again meant changing the account's GitHub billing. Without CI no pull request can meet the definition of done in `CLAUDE.md`.
- **Decision.** The owner made the repository public on 2026-10-16 and chose not to change billing. At the end of the phase-A work order (`docs/reviews/2026-10-16-expert-brief.md`) the owner decides whether to make it private again.
- **Checked before the flip.** The working tree and all 192 commits were scanned for secrets: no tracked secret files, no private-key blocks or tokens in the tree or in any commit, and the only IP addresses are RFC 5737 test addresses.
- **Alternatives.** Paying for Actions: declined by the owner. Staying private, running the gates locally and making the repository public only for brief CI windows: rejected, because a fork taken during any public window survives making the repository private again, so the windows would protect almost nothing.
- **Consequence.** Everything committed, history included, is world-readable. `Legacy/`, `legacy-review.md`, `assets/envato/` and `.env` stay out of git as `CLAUDE.md` already requires, and a secret that is ever committed is rotated, not merely deleted.
- **To reverse.** Make the repository private again in its GitHub settings, then pay for Actions or accept local gates only; forks and clones taken while it was public are not withdrawn.
