# A resend overwrites the stored decision letter

Decided by the owner on 2026-10-01. Copied unedited from `docs/superpowers/plans/backlog.md` (Decisions); the launch checklist records it in section 1.

**Decided (owner, 2026-10-01): a resend overwrites the stored letter.** A *changed* decision appends a new `submission_decisions` row and leaves the superseded row's letter alone. A plain *resend* of the same decision re-renders and overwrites `letter_subject`, `letter_markdown` and `notified_at` on the current row, because the author is now holding the newer email and `/s/{token}` has to show what is in their inbox. The individual send history is not lost — `email_logs` has a row per send — but the *text* of the superseded letter is. Keeping every send as its own row is one more table or one more column, if it is ever wanted.
