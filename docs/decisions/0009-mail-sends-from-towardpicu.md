# Mail sends from towardpicu.com

Decided by the owner on 2026-10-01. Copied unedited from `docs/superpowers/plans/backlog.md` (Launch); the launch checklist records it in section 2.

**Decided (owner, 2026-10-01): mail sends from `towardpicu.com`**, through the owner's towardpicu.com SMTP, so `.env.example`'s `MAIL_FROM_ADDRESS="cass@towardpicu.com"` was right and SPF and DKIM belong on `towardpicu.com`. `CASS_CONTACT_EMAIL=cass@towardpcc.com` is not a sender: it is the inbox the contact form writes to and the address the footer, About, Privacy and Terms pages show, so it only has to receive mail.
