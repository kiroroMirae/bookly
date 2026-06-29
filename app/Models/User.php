<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<EventType, $this> */
    public function eventTypes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EventType::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<AvailabilityWindow, $this> */
    public function availabilityWindows(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AvailabilityWindow::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Booking, $this> */
    public function bookings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Booking::class, 'host_user_id');
    }
}
