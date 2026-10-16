# Security exceptions

A high or critical finding from `composer audit` or `npm audit --omit=dev` blocks a merge unless it has a row here: the advisory, why it is accepted for now, and the date by which it is reviewed again. Remove the row in the pull request that fixes the finding.

| Advisory | Package and version | Why accepted for now | Review by |
|---|---|---|---|
