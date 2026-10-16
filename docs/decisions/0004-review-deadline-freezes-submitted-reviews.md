# The review deadline freezes a submitted review, not a draft

Decided by the owner on 2026-10-01. Copied unedited from `docs/superpowers/plans/backlog.md` (Reviewing); the launch checklist records it in section 1.

**Decided (owner, 2026-10-01): the review deadline freezes a submitted review, not a draft.** Plan 4 makes a *submitted* review read-only at the review deadline (no reopen) but lets a *draft* still be saved and submitted while the conference is in `reviewing`. The alternative reading of "after the deadline reviews are read-only" freezes everything — but `reviewer_overdue`, a spec 5.9 key whose platform default Plan 3 already ships, tells a reviewer after the deadline to "complete them as soon as you can", which that reading makes impossible. Making it strict later is one added clause in `SubmitReview::blockers()` (`! $conference->reviewWindowIsOpen()`) and inverting `it('still lets outstanding work be finished after the deadline')` in `tests/Unit/SubmitReviewTest.php`.
