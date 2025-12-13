<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'age',
        'gender',
        'weight',
        'weight_unit',
        'height',
        'height_unit',
        'goal',
        'activity_level',
        'daily_calorie_goal',
        'daily_steps_goal',
        'daily_water_goal',
        'dob',
        'phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'dob' => 'date',
    ];

    // Relationships
    public function dailyActivities()
    {
        return $this->hasMany(DailyActivity::class);
    }

    public function userWorkouts()
    {
        return $this->hasMany(UserWorkout::class);
    }

    public function userMealPlans()
    {
        return $this->hasMany(UserMealPlan::class);
    }

    public function userProgress()
    {
        return $this->hasMany(UserProgress::class);
    }

    public function favorites()
    {
        return $this->hasMany(UserFavorite::class);
    }

    public function chats()
    {
        return $this->hasMany(Chat::class);
    }

    public function aiChats()
    {
        return $this->hasMany(AiChat::class);
    }

    public function searchLogs()
    {
        return $this->hasMany(SearchLog::class);
    }

    public function settings()
    {
        return $this->hasMany(UserSetting::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function userPreferences()
    {
        return $this->hasOne(UserPreference::class);
    }

    public function achievements()
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function userGoal()
    {
        return $this->hasOne(UserGoal::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
