<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ReviewQuestionType;
use App\Models\Conference;
use App\Models\ReviewForm;
use Illuminate\Support\Facades\DB;

class CreateDefaultReviewForm
{
    /**
     * The eight scoring questions from the legacy CASS review form verbatim,
     * plus the legacy recommendation question reworded for clarity (the
     * original reads "Do you recommend this abstract for oral presentation (1
     * is dont recommend & 5 Strongly recommend)"), all Likert 1-5 with equal
     * weight. Organizers edit, reorder and delete them freely until the first
     * review is submitted (Plan 4 sets review_forms.locked_at then).
     *
     * @var list<string>
     */
    public const TEMPLATE = [
        'Originality and Innovation: How original and innovative is the research presented in the abstract?',
        'Relevance to the Field: How relevant is the research to the field or theme of the conference?',
        'Methodological Rigor: How rigorous and appropriate are the methods used in the research?',
        'Clarity of Presentation: How clear and well-organized is the abstract?',
        'Results and Conclusions: How compelling and well-supported are the results and conclusions presented?',
        'Potential Impact: What is the potential impact of the research on the field?',
        'Interdisciplinary Appeal: Does the research have appeal or implications beyond its immediate field?',
        'Engagement Potential: How likely is the abstract to engage the audience during the presentation?',
        'Do you recommend this abstract for oral presentation? (1 = do not recommend, 5 = strongly recommend)',
    ];

    /**
     * Idempotent: returns the existing active form untouched if there is one,
     * so it is safe to call from the create page, from a relation manager and
     * from the Plan 6 legacy import.
     */
    public function handle(Conference $conference): ReviewForm
    {
        $existing = $conference->reviewForm()->first();

        if ($existing instanceof ReviewForm) {
            return $existing;
        }

        return DB::transaction(function () use ($conference): ReviewForm {
            $form = new ReviewForm(['name' => 'Review form']);
            $form->conference()->associate($conference);
            // Eloquent never reads column defaults back after an INSERT, and
            // `is_active` is not fillable, so without this the returned
            // instance reports null and every caller that trusts it (the
            // publish gate, the relation manager) sees an inactive form.
            $form->is_active = true;
            $form->save();

            foreach (self::TEMPLATE as $index => $prompt) {
                $form->questions()->create([
                    'prompt' => $prompt,
                    'type' => ReviewQuestionType::Likert,
                    'scale_min' => 1,
                    'scale_max' => 5,
                    'weight' => '1.00',
                    'required' => true,
                    'sort' => $index + 1,
                ]);
            }

            $conference->setRelation('reviewForm', $form);

            return $form;
        });
    }
}
