<?php

namespace App\Services\Audio;

use InvalidArgumentException;

/**
 * Reads and writes the headers of PCM WAV files.
 */
class Wav
{
    /**
     * Wrap headerless 16-bit little-endian PCM samples in a WAV header.
     */
    public static function fromPcm16(string $pcm, int $sampleRate, int $channels = 1): string
    {
        $blockAlign = $channels * 2;

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, $channels, $sampleRate, $sampleRate * $blockAlign, $blockAlign, 16)
            .'data'.pack('V', strlen($pcm))
            .$pcm;
    }

    /**
     * The length of a WAV file in seconds.
     *
     * A data chunk whose size is missing or too large (as in a streamed WAV)
     * is taken to run to the end of the file.
     *
     * @throws InvalidArgumentException When the bytes are not a WAV file.
     */
    public static function duration(string $wav): float
    {
        if (strlen($wav) < 12 || substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            throw new InvalidArgumentException('The audio is not a WAV file.');
        }

        $byteRate = null;
        $offset = 12;

        while ($offset + 8 <= strlen($wav)) {
            $id = substr($wav, $offset, 4);
            $size = self::uint32($wav, $offset + 4);

            if ($id === 'fmt ') {
                $byteRate = self::uint32($wav, $offset + 16);
            }

            if ($id === 'data') {
                if (! $byteRate) {
                    break;
                }

                $available = strlen($wav) - $offset - 8;

                return (float) (min($size, $available) / $byteRate);
            }

            $offset += 8 + $size + ($size % 2);
        }

        throw new InvalidArgumentException('The WAV file has no audio data.');
    }

    /**
     * Read a little-endian 32-bit number from the bytes at the offset.
     *
     * @throws InvalidArgumentException When the file ends before the number does.
     */
    protected static function uint32(string $wav, int $offset): int
    {
        $number = strlen($wav) >= $offset + 4 ? unpack('V', $wav, $offset) : false;

        if ($number === false) {
            throw new InvalidArgumentException('The audio is not a WAV file.');
        }

        return $number[1];
    }
}
