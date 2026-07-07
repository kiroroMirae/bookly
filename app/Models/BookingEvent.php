<?php

namespace App\Models;

use App\Enums\BookingActor;
use App\Enums\BookingEventKind;
use Database\Factories\BookingEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingEvent extends Model
{
    /** @use HasFactory<BookingEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'booking_id',
        'actor',
        'event',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'actor' => BookingActor::class,
            'event' => BookingEventKind::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
