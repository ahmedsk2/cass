<?php

declare(strict_types=1);

namespace App\Support\Reviews;

/**
 * The result of AutoAssignReviewers::plan(). Spec 5.5 requires the result to be
 * "shown for confirmation before saving", so this exists to be rendered: rows
 * carry the names, not only the ids, and a submission that could not reach its
 * target is a sentence rather than a silence.
 */
final readonly class AssignmentPlan
{
    /**
     * @param  list<array{submission_id: int, label: string, add: list<int>, names: list<string>}>  $rows
     * @param  list<string>  $shortfalls  one sentence per submission left short of the target
     * @param  array<int, int>  $loads  final assignment count per reviewer user id
     */
    public function __construct(
        public array $rows,
        public array $shortfalls,
        public int $assignments,
        public array $loads,
    ) {}

    public function isEmpty(): bool
    {
        return $this->assignments === 0;
    }
}
