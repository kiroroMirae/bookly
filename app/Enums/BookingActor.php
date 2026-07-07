<?php

namespace App\Enums;

enum BookingActor: string
{
    case Host = 'host';
    case Guest = 'guest';
    case System = 'system';
}
