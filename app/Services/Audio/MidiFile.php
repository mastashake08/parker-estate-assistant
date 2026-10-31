<?php

namespace App\Services\Audio;

/**
 * Writes Standard MIDI Files (format 1): a conductor track with the tempo
 * and time signature, then one track per stem.
 *
 * Note times are in beats (quarter notes), as the composer writes them.
 */
class MidiFile
{
    /**
     * Ticks per quarter note.
     */
    public const PPQ = 480;

    /**
     * Write a MIDI file.
     *
     * @param  list<array{name: string, program: int, channel: int, drums: bool, notes: list<array{pitch: int, start: float, duration: float, velocity: int}>}>  $stems
     */
    public static function write(array $stems, int $tempo, int $beatsPerBar = 4, string $title = ''): string
    {
        $tracks = [self::conductor($tempo, $beatsPerBar, $title)];

        foreach ($stems as $stem) {
            $tracks[] = self::track($stem);
        }

        $file = 'MThd'.pack('Nnnn', 6, 1, count($tracks), self::PPQ);

        foreach ($tracks as $events) {
            $file .= 'MTrk'.pack('N', strlen($events)).$events;
        }

        return $file;
    }

    /**
     * The first track: the title, tempo and time signature.
     */
    protected static function conductor(int $tempo, int $beatsPerBar, string $title): string
    {
        $microseconds = intdiv(60_000_000, max(1, $tempo));

        $events = '';

        if ($title !== '') {
            $events .= self::meta(0x03, $title);
        }

        $events .= self::meta(0x51, substr(pack('N', $microseconds), 1))
            // Numerator, denominator as a power of two (2 = quarter notes), clocks per click, 32nds per quarter.
            .self::meta(0x58, pack('CCCC', $beatsPerBar, 2, 24, 8));

        return $events.self::endOfTrack();
    }

    /**
     * One stem as a track: its name, instrument and notes.
     *
     * @param  array{name: string, program: int, channel: int, drums: bool, notes: list<array{pitch: int, start: float, duration: float, velocity: int}>}  $stem
     */
    protected static function track(array $stem): string
    {
        $channel = $stem['channel'] & 0x0F;
        $events = self::meta(0x03, $stem['name']);

        if (! $stem['drums']) {
            $events .= self::varLength(0).pack('CC', 0xC0 | $channel, $stem['program'] & 0x7F);
        }

        // Each note becomes an on and an off; offs sort before ons at the same tick
        // so a repeated note is released before it sounds again.
        $timeline = [];

        foreach ($stem['notes'] as $note) {
            $start = self::ticks($note['start']);
            $end = max($start + 1, self::ticks($note['start'] + $note['duration']));

            $timeline[] = [$start, 1, pack('CCC', 0x90 | $channel, $note['pitch'] & 0x7F, max(1, $note['velocity'] & 0x7F))];
            $timeline[] = [$end, 0, pack('CCC', 0x80 | $channel, $note['pitch'] & 0x7F, 0)];
        }

        usort($timeline, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $now = 0;

        foreach ($timeline as [$tick, , $message]) {
            $events .= self::varLength($tick - $now).$message;
            $now = $tick;
        }

        return $events.self::endOfTrack();
    }

    /**
     * Convert beats to ticks.
     */
    protected static function ticks(float $beats): int
    {
        return (int) round(max(0.0, $beats) * self::PPQ);
    }

    /**
     * A meta event at delta time zero.
     */
    protected static function meta(int $type, string $data): string
    {
        return self::varLength(0).pack('CC', 0xFF, $type).self::varLength(strlen($data)).$data;
    }

    /**
     * The end-of-track meta event.
     */
    protected static function endOfTrack(): string
    {
        return self::meta(0x2F, '');
    }

    /**
     * Encode a number as a MIDI variable-length quantity.
     */
    public static function varLength(int $value): string
    {
        $bytes = chr($value & 0x7F);

        while ($value >>= 7) {
            $bytes = chr(($value & 0x7F) | 0x80).$bytes;
        }

        return $bytes;
    }
}
