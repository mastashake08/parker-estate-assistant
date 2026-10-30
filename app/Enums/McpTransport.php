<?php

namespace App\Enums;

enum McpTransport: string
{
    case Stdio = 'stdio';
    case Http = 'http';
}
