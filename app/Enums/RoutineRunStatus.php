<?php

namespace App\Enums;

enum RoutineRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case WaitingForApproval = 'waiting_for_approval';
    case Failed = 'failed';
}
