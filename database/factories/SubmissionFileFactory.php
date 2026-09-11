<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SubmissionFile> */
class SubmissionFileFactory extends Factory
{
    protected $model = SubmissionFile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $ulid = (string) Str::ulid();
        $sha = hash('sha256', $ulid);

        return [
            'submission_id' => Submission::factory(),
            'ulid' => $ulid,
            'original_name' => 'abstract.pdf',
            // The same layout StoreSubmissionFile writes, so a factory-made row
            // and a real upload are indistinguishable to the download route.
            'path' => substr($sha, 0, 2).'/'.$ulid.'.pdf',
            'mime' => 'application/pdf',
            'size' => 12_345,
            'sha256' => $sha,
            'sort' => 1,
        ];
    }
}
