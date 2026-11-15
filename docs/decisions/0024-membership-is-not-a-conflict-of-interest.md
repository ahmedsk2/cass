# Organisation membership is not a conflict of interest for auto-assign

- **Context.** For a small society the programme chair reviews too.
- **Decision.** Proposed by the session on 2026-09-11 (Plan 4, `e5cdf36`, recorded in the backlog on 2026-09-12) and accepted by the owner with the merge of #NN, which adds this file (the owner approves that pull request): organisation membership is not a conflict of interest for auto-assign.
- **Consequence.** Treating membership as a conflict would empty the pool of the people who know the field. The real conflict it sometimes proxies for, the reviewer working where the author works, is caught by the email-domain and affiliation rules.
- **To reverse.** Add a membership clause to `App\Actions\Reviews\AutoAssignReviewers::conflicts()`, whose docblock states the rule (`app/Actions/Reviews/AutoAssignReviewers.php:189`), and invert `it('does not treat organization membership as a conflict')` in `tests/Unit/AutoAssignReviewersTest.php`, whose comment records the reason.
