<?php

namespace App\Enums;

enum RoutineFrequency: string
{
    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';
    case Hourly = 'hourly';
    case Custom = 'custom';
}
