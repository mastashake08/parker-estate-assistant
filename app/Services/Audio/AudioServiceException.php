<?php

namespace App\Services\Audio;

use RuntimeException;

/**
 * A speech, music or MIDI engine could not be reached or refused a request.
 * The message is written to be shown to the user.
 */
class AudioServiceException extends RuntimeException {}
