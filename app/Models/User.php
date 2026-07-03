<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'timezone',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'calendar_feed_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<EventType, $this> */
    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    /** @return HasMany<AvailabilityWindow, $this> */
    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(AvailabilityWindow::class);
    }

    /** @return HasMany<AvailabilityOverride, $this> */
    public function availabilityOverrides(): HasMany
    {
        return $this->hasMany(AvailabilityOverride::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'host_user_id');
    }

    /**
     * Return the calendar feed token, generating and persisting one on first use.
     */
    public function getOrCreateCalendarFeedToken(): string
    {
        if (blank($this->calendar_feed_token)) {
            $this->forceFill(['calendar_feed_token' => Str::random(64)])->save();
        }

        return $this->calendar_feed_token;
    }
}
