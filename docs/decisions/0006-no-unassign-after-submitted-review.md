# No unassigning a reviewer who has already submitted

Decided by the owner on 2026-10-01. Copied unedited from `docs/superpowers/plans/backlog.md` (Reviewing); the launch checklist records it in section 1.

**Decided (owner, 2026-10-01): no unassigning a reviewer who has already submitted, as spec 5.5 says** — assignments change "until the review is submitted". Plan 4 allowed it, but the submitted review always survived (only the assignment row went, and Plan 5 averages `reviews` rather than `review_assignments`), so allowing it could only leave the coverage summary describing the wrong people. `AssignReviewers::blockers()` now refuses a set that drops a reviewer with a submitted review of that abstract; tested in `ConferenceAssignmentsTest`. Removing the reviewer from the conference altogether (`ConferenceReviewerPolicy`, above) is unchanged.
