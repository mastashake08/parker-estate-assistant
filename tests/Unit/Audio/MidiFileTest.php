<?php

use App\Services\Audio\MidiFile;

/**
 * Split a MIDI file into its header and track chunks.
 *
 * @return array{header: array{format: int, tracks: int, ppq: int}, tracks: list<string>}
 */
function readMidi(string $file): array
{
    $header = unpack('a4id/Nlength/nformat/ntracks/nppq', substr($file, 0, 14));
    $tracks = [];
    $offset = 14;

    while ($offset < strlen($file)) {
        $length = unpack('N', substr($file, $offset + 4, 4))[1];
        $tracks[] = substr($file, $offset + 8, $length);
        $offset += 8 + $length;
    }

    return ['header' => $header, 'tracks' => $tracks];
}

function stem(array $overrides = []): array
{
    return [
        'name' => 'Piano',
        'program' => 4,
        'channel' => 2,
        'drums' => false,
        'notes' => [['pitch' => 60, 'start' => 0, 'duration' => 1, 'velocity' => 90]],
        ...$overrides,
    ];
}

test('variable-length quantities are encoded as the MIDI spec gives them', function (int $value, string $hex) {
    expect(bin2hex(MidiFile::varLength($value)))->toBe($hex);
})->with([
    [0, '00'],
    [0x7F, '7f'],
    [0x80, '8100'],
    [0x3FFF, 'ff7f'],
    [0x4000, '818000'],
    [0x0FFFFFFF, 'ffffff7f'],
]);

test('a file has a conductor track and one track per stem', function () {
    $midi = readMidi(MidiFile::write([stem(), stem(['name' => 'Bass'])], 120));

    expect(substr(MidiFile::write([stem()], 120), 0, 4))->toBe('MThd')
        ->and($midi['header'])->toMatchArray(['format' => 1, 'tracks' => 3, 'ppq' => MidiFile::PPQ])
        ->and($midi['tracks'])->toHaveCount(3);
});

test('the conductor track holds the tempo and time signature', function () {
    $conductor = readMidi(MidiFile::write([stem()], 96, 3))['tracks'][0];

    // 96 BPM is 625000 microseconds per beat.
    expect($conductor)->toContain("\xFF\x51\x03".substr(pack('N', 625000), 1))
        ->and($conductor)->toContain("\xFF\x58\x04\x03\x02\x18\x08")
        ->and($conductor)->toEndWith("\x00\xFF\x2F\x00");
});

test('a stem sets its instrument and plays its notes on its channel', function () {
    $track = readMidi(MidiFile::write([stem()], 120))['tracks'][1];

    expect($track)->toContain("\x00\xC2\x04")       // Program 4 on channel 3
        ->and($track)->toContain("\x00\x92\x3C\x5A")   // Note on, middle C, velocity 90
        ->and($track)->toContain("\x83\x60\x82\x3C\x00"); // 480 ticks later, note off
});

test('drum stems have no program change', function () {
    $track = readMidi(MidiFile::write([stem(['channel' => 9, 'drums' => true])], 120))['tracks'][1];

    expect($track)->not->toContain("\xC9");
});

test('a repeated note is released before it sounds again', function () {
    $track = readMidi(MidiFile::write([stem(['notes' => [
        ['pitch' => 60, 'start' => 0, 'duration' => 1, 'velocity' => 90],
        ['pitch' => 60, 'start' => 1, 'duration' => 1, 'velocity' => 90],
    ]])], 120))['tracks'][1];

    expect(strpos($track, "\x82\x3C\x00"))->toBeLessThan(strrpos($track, "\x92\x3C\x5A"));
});
