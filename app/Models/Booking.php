<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'coach_id',
        'service_id',
        'status',
        'payment_status',
        'stripe_payment_intent_id',
        'amount',
        'booked_for',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'booked_for' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function coach()
    {
        return $this->belongsTo(Coach::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
