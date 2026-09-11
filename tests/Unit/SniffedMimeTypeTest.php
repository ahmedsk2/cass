<?php

declare(strict_types=1);

use App\Support\Files\SniffedMimeType;

it('reads the real type of the pdf fixture', function () {
    expect(SniffedMimeType::forPath(base_path('tests/Fixtures/abstract.pdf')))->toBe('application/pdf');
});

it('sees through a png wearing a pdf name', function () {
    $path = base_path('tests/Fixtures/not-really.pdf');

    expect(SniffedMimeType::forPath($path))->toBe('image/png')
        ->and(SniffedMimeType::matches(SniffedMimeType::forPath($path), 'pdf'))->toBeFalse();
});

it('accepts each type the conference form offers', function (string $extension, string $mime) {
    expect(SniffedMimeType::matches($mime, $extension))->toBeTrue();
})->with([
    ['pdf', 'application/pdf'],
    ['doc', 'application/msword'],
    // Old magic databases answer application/CDFV2 for an OLE2 container.
    ['doc', 'application/CDFV2'],
    ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    // A .docx *is* a zip, and plenty of magic databases stop there.
    ['docx', 'application/zip'],
    // Case and extension case must not matter.
    ['PDF', 'APPLICATION/PDF'],
]);

it('rejects an unknown extension, an unknown mime and a null sniff', function () {
    expect(SniffedMimeType::matches('application/pdf', 'exe'))->toBeFalse()
        ->and(SniffedMimeType::matches('application/x-dosexec', 'pdf'))->toBeFalse()
        ->and(SniffedMimeType::matches(null, 'pdf'))->toBeFalse();
});

it('sniffs a stream without consuming it for the caller', function () {
    $stream = fopen(base_path('tests/Fixtures/abstract.pdf'), 'rb');

    expect(SniffedMimeType::forStream($stream))->toBe('application/pdf')
        // StoreSubmissionFile hashes and copies the same handle afterwards, so
        // the sniff has to rewind.
        ->and(ftell($stream))->toBe(0);

    fclose($stream);
});

it('lists exactly the extensions the conference form offers', function () {
    expect(SniffedMimeType::allowedExtensions())->toBe(['pdf', 'doc', 'docx']);
});
