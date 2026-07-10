<?php

namespace App\Models;

use App\Enums\BookingActor;
use App\Enums\BookingEventKind;
use App\Enums\BookingStatus;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_type_id
 * @property int $host_user_id
 * @property string $guest_name
 * @property string $guest_email
 * @property string $guest_timezone
 * @property string|null $location
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property BookingStatus $status
 * @property string|null $cancellation_reason
 * @property string|null $host_notes
 * @property Carbon|null $reminder_sent_at
 * @property int $ics_sequence
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected $fillable = [
        'event_type_id',
        'host_user_id',
        'guest_name',
        'guest_email',
        'guest_timezone',
        'location',
        'starts_at',
        'ends_at',
        'status',
        'cancellation_reason',
        'host_notes',
        'reminder_sent_at',
        'ics_sequence',
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

    /** @return BelongsTo<EventType, $this> */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /** @return HasMany<BookingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->orderBy('id');
    }

    /**
     * Append an immutable audit-trail entry for a lifecycle transition.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordEvent(BookingEventKind $event, BookingActor $actor, ?array $metadata = null): BookingEvent
    {
        return $this->events()->create([
            'event' => $event,
            'actor' => $actor,
            'metadata' => $metadata,
        ]);
    }
}
