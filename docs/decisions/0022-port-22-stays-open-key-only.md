# Port 22 stays open, key-only

- **Context.** The launch checklist (section 2) asked for port 22 on the OCI security list to be restricted to known addresses, since early commits of the public repository held the origin IP and SSH details. Ports 80 and 443 already admit only Cloudflare's ranges.
- **Decision.** The owner's, on 2026-09-25 as recorded on 2026-11-06 (`303d6de`, in the backlog, checklist section 2 and HANDOVER B3), and re-affirmed on 2026-11-06: port 22 stays open, key-only, and the checklist box is ticked with that reason.
- **Consequence.** None recorded beyond the decision.
- **To reverse.** In the OCI Console, let the VCN's security list admit tcp 22 only from known addresses, as the checklist's original item asked. Nothing in this repository changes, and no test pins it.
