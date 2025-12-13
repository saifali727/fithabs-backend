<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Coach extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'bio',
        'profile_image',
        'specializations',
        'certifications',
        'phone',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'specializations' => 'array',
        'certifications' => 'array',
        'password' => 'hashed',
    ];

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}