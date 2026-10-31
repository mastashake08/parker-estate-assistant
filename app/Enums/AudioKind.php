<?php

namespace App\Enums;

enum AudioKind: string
{
    case Speech = 'speech';
    case Music = 'music';
    case Midi = 'midi';
    case Vocals = 'vocals';

    /**
     * The extension of the file this kind of generation produces.
     */
    public function extension(): string
    {
        return $this === self::Midi ? 'mid' : 'wav';
    }

    /**
     * The MIME type of the file this kind of generation produces.
     */
    public function mimeType(): string
    {
        return $this === self::Midi ? 'audio/midi' : 'audio/wav';
    }
}
