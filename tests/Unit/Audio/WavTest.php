<?php

use App\Services\Audio\Wav;

test('pcm is wrapped in a wav header', function () {
    $wav = Wav::fromPcm16(str_repeat("\x01\x00", 24000), 24000);

    expect(substr($wav, 0, 4))->toBe('RIFF')
        ->and(substr($wav, 8, 8))->toBe('WAVEfmt ')
        ->and(unpack('V', substr($wav, 4, 4))[1])->toBe(strlen($wav) - 8)
        ->and(unpack('vformat/vchannels/Vrate', substr($wav, 20, 8)))->toBe(['format' => 1, 'channels' => 1, 'rate' => 24000])
        ->and(Wav::duration($wav))->toBe(1.0);
});

test('the length of a stereo file counts both channels', function () {
    expect(Wav::duration(Wav::fromPcm16(str_repeat("\0", 32000 * 4), 32000, 2)))->toBe(1.0);
});

test('a streamed wav without a data length is read to the end of the file', function () {
    $wav = Wav::fromPcm16(str_repeat("\0\0", 12000), 24000);
    $streamed = substr_replace($wav, "\xFF\xFF\xFF\xFF", 40, 4);

    expect(Wav::duration($streamed))->toBe(0.5);
});

test('other files are refused', function () {
    Wav::duration('ID3 not a wav file');
})->throws(InvalidArgumentException::class);

test('a file that ends inside its format chunk is refused', function () {
    $wav = Wav::fromPcm16(str_repeat("\0\0", 100), 24000);

    Wav::duration(substr($wav, 0, 26));
})->throws(InvalidArgumentException::class, 'The audio is not a WAV file.');
