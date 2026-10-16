# Only an owner or admin may send decision letters

Decided by the owner on 2026-10-01. Copied unedited from `docs/superpowers/plans/backlog.md` (Decisions); the launch checklist records it in section 1.

**Decided (owner, 2026-10-01): only an owner or admin may send decision letters.** `SubmissionPolicy::decide()` follows spec section 4's "Invite reviewers, assign, decide" row and allows **every** organization member down to a plain `member`. `ConferencePolicy::sendDecisions()` deliberately does **not**: it is owner/admin only, because one click emails every author in the conference and cannot be un-sent. (It lives on `ConferencePolicy` rather than `SubmissionPolicy` because Laravel resolves a policy from the first argument's class and every call site passes the conference.) That is a narrowing of the spec table, argued in the policy's own docblock, and it stands.
