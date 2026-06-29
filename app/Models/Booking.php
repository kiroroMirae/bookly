<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    /** @use HasFactory<\Database\Factories\BookingFactory> */
    use HasFactory;

    protected $fillable = [
        'event_type_id',
        'host_user_id',
        'guest_name',
        'guest_email',
        'guest_timezone',
        'starts_at',
        'ends_at',
        'status',
        'cancellation_reason',
        'host_notes',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'status' => BookingStatus::class,
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<EventType, $this> */
    public function eventType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function host(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }
}
