<?php

use App\Services\Audio\DsScore;

function dictionary(): array
{
    return [
        'entries' => [
            'hello' => ['hh', 'ah', 'l', 'ow'],
            'world' => ['w', 'er', 'l', 'd'],
            'strong' => ['s', 't', 'r', 'ao', 'ng'],
            'i' => ['ay'],
            'ah' => ['aa'],
        ],
        'vowels' => ['aa', 'ah', 'ao', 'ay', 'er', 'ow'],
    ];
}

function line(array $notes, float $rest = 1): array
{
    return ['notes' => array_map(fn (array $note) => ['lyric' => $note[0], 'pitch' => $note[1], 'beats' => $note[2]], $notes), 'rest' => $rest];
}

/**
 * @return list<string>
 */
function field(array $segment, string $key): array
{
    return explode(' ', (string) $segment[$key]);
}

test('a line becomes a segment that DiffSinger can sing', function () {
    $score = DsScore::build([line([['Hello,', 60, 1], ['-', 62, 1], ['world', 64, 2]])], 120, dictionary(), 'en');
    $segment = $score['segments'][0];

    // Each word's group starts at its vowel; the consonants before it end the word before.
    expect($segment['lang'])->toBe('en')
        ->and($segment['text'])->toBe('SP Hello, world SP')
        ->and($segment['ph_seq'])->toBe('SP hh ah l ow w er l d SP')
        ->and($segment['ph_num'])->toBe('2 4 3 1')
        ->and($segment['note_seq'])->toBe('rest C4 D4 E4 rest')
        ->and($segment['note_slur'])->toBe('0 0 1 0 0')
        ->and($segment['note_dur'])->toBe('0.5 0.5 0.5 1 0.5')
        ->and($segment['f0_timestep'])->toBe('0.005');
});

test('the phoneme and note lists agree with each other', function () {
    $score = DsScore::build([
        line([['hello', 60, 1], ['world', 62, 1.5]], 2),
        line([['-', 60, 0.5], ['I', 67, 1], ['rest', 0, 1], ['strong', 65, 1.5]], 2),
    ], 96, dictionary());

    foreach ($score['segments'] as $segment) {
        $durations = array_map(floatval(...), field($segment, 'ph_dur'));
        $notes = array_map(floatval(...), field($segment, 'note_dur'));
        $slurs = array_map(intval(...), field($segment, 'note_slur'));

        expect(field($segment, 'ph_seq'))->toHaveCount(array_sum(array_map(intval(...), field($segment, 'ph_num'))))
            ->and($durations)->toHaveCount(count(field($segment, 'ph_seq')))
            ->and(field($segment, 'ph_num'))->toHaveCount(count(array_filter($slurs, fn (int $slur) => $slur === 0)))
            ->and(abs(array_sum($durations) - array_sum($notes)))->toBeLessThan(0.001)
            ->and(min($durations))->toBeGreaterThan(0)
            ->and(count(field($segment, 'f0_seq')))->toEqualWithDelta(array_sum($notes) / DsScore::F0_TIMESTEP, 1);
    }
});

test('lines are placed one after another, after a beat of lead-in', function () {
    $score = DsScore::build([line([['hello', 60, 4]], 1), line([['world', 62, 2]])], 120, dictionary());

    // The second line starts at beat 1 + 4 + 1 = 6; its segment starts a beat earlier, at beat 5.
    expect($score['segments'][0]['offset'])->toBe(0.0)
        ->and($score['segments'][1]['offset'])->toBe(2.5)
        ->and(array_column($score['notes'], 'start'))->toBe([1.0, 6.0]);
});

test('the melody, lyrics and unknown words are returned', function () {
    $score = DsScore::build([line([['hello', 60, 1], ['zyx', 62, 1], ['-', 64, 1]]), line([['rest', 60, 4]])], 120, dictionary());

    expect($score['lyrics'])->toBe('hello zyx')
        ->and($score['unknown'])->toBe(['zyx'])
        ->and($score['notes'])->toHaveCount(3)
        // A line of rests is not sung.
        ->and($score['segments'])->toHaveCount(1)
        // Unknown words are sung on an open vowel.
        ->and($score['segments'][0]['ph_seq'])->toContain('aa');
});

test('a slur with no word before it is a rest', function () {
    $score = DsScore::build([line([['-', 60, 1], ['hello', 62, 1]])], 120, dictionary());

    expect($score['segments'][0]['note_seq'])->toBe('rest rest D4 rest')
        ->and($score['notes'])->toHaveCount(1);
});

test('note names follow scientific pitch notation', function () {
    expect(DsScore::noteName(60))->toBe('C4')
        ->and(DsScore::noteName(61))->toBe('C#4')
        ->and(DsScore::noteName(69))->toBe('A4')
        ->and(DsScore::noteName(21))->toBe('A0');
});
