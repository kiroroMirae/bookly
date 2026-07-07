<?php

namespace App\Enums;

enum BookingEventKind: string
{
    case Created = 'created';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';
}
