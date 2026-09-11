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

it('refuses a stream it cannot rewind instead of eating the bytes the caller needs', function () {
    // Windows has no AF_UNIX socketpair; Linux has no AF_INET one.
    $pair = @stream_socket_pair(
        DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX,
        STREAM_SOCK_STREAM,
        STREAM_IPPROTO_IP,
    );

    if (! is_array($pair)) {
        $this->markTestSkipped('This platform has no socket pair to make a non-seekable handle from.');
    }

    [$read, $write] = $pair;
    fwrite($write, "%PDF-1.4\nnot really an abstract\n");
    fclose($write);

    // A non-local Livewire temporary-upload disk hands StoreSubmissionFile
    // exactly this: a handle whose ftell() answers 0 and whose rewind() fails.
    // Sniffing it would consume the head and store a truncated file that had
    // just passed the MIME check, so the answer is null and the caller falls
    // back to forPath().
    expect(SniffedMimeType::forStream($read))->toBeNull()
        ->and(stream_get_contents($read))->toBe("%PDF-1.4\nnot really an abstract\n");

    fclose($read);
});

it('refuses a handle that reports itself unseekable', function () {
    $stream = fopen('php://output', 'w');

    expect(SniffedMimeType::forStream($stream))->toBeNull();

    fclose($stream);
});

it('answers null rather than throwing for a path, a handle or a file it cannot read', function () {
    // The three robustness guards nothing else exercises: forPath()'s @fopen
    // false branch, forStream()'s is_resource() branch, and an empty file,
    // whose fread() gives finfo nothing to work with. All three are refusals
    // that StoreSubmissionFile turns into a message for the author; a refactor
    // dropping any of them is a warning or a fatal on a public upload.
    $empty = tempnam(sys_get_temp_dir(), 'cass');
    $handle = fopen($empty, 'rb');

    expect(SniffedMimeType::forPath(base_path('tests/Fixtures/no-such-file.pdf')))->toBeNull()
        ->and(SniffedMimeType::forStream('not a resource'))->toBeNull()
        ->and(SniffedMimeType::forStream(null))->toBeNull()
        ->and(SniffedMimeType::forStream($handle))->toBeNull()
        ->and(SniffedMimeType::forPath($empty))->toBeNull();

    fclose($handle);
    unlink($empty);
});

it('lists exactly the extensions the conference form offers', function () {
    expect(SniffedMimeType::allowedExtensions())->toBe(['pdf', 'doc', 'docx']);
});
