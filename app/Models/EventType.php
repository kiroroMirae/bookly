<?php

namespace App\Models;

use Database\Factories\EventTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventType extends Model
{
    /** @use HasFactory<EventTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'duration_minutes',
        'color',
        'is_active',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'minimum_notice_minutes',
        'booking_window_days',
        'max_bookings_per_day',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'minimum_notice_minutes' => 'integer',
            'booking_window_days' => 'integer',
            'max_bookings_per_day' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
