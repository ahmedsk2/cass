# Only an owner or admin may withdraw an abstract or resend its status link

Decided by the owner on 2026-10-01. Copied unedited from `docs/superpowers/plans/backlog.md` (Submissions); the launch checklist records it in section 1.

**Decided (owner, 2026-10-01): only an owner or admin may withdraw an abstract or resend its status link.** Spec section 4 gives "Submit / edit / withdraw abstract" to the author alone; a withdrawal is irreversible (nothing in either panel turns `Withdrawn` back) and a new status token kills the link the author is already holding. `SubmissionPolicy::withdraw()` and `resendLink()` used to mirror `view()` — every member down to a plain `member`, and through `ReviewerScope` every active reviewer of a conference in review — and now ask `canManageOrganization()`. Tested in `SubmissionResourceTest`. Both are still logged with the actor.
