# Every organisation member may invite and remove reviewers

Decided by the owner on 2026-10-01. Copied unedited from `docs/superpowers/plans/backlog.md` (Reviewing); the launch checklist records it in section 1.

**Decided (owner, 2026-10-01): every organization member may invite and remove reviewers, as spec section 4 says.** `ReviewerInvitationPolicy` and `ConferenceReviewerPolicy` mirror spec section 4's "Invite reviewers, assign, decide", which is **every** organization member down to a plain `member` — so a plain member can remove a reviewer mid-review (deleting their assignments) and invite anyone. Member *management* is owner/admin only, which is the spec's other row. Both are logged with the actor, and the invitation send path is metered per actor (`CASS_INVITATION_SEND_LIMIT`, 200 an hour) with one paste capped at `ReviewerList::MAX_ENTRIES`, so the blast radius is bounded rather than unbounded. Narrowing later is the two reviewer policies to `canManageOrganization()`.
