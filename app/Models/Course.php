<?php

namespace App\Models;

use App\Enums\CourseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'instructor_id',
        'capacity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'status' => CourseStatus::class,
        ];
    }

    /**
     * 講師
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * 公開中の講座のみを取得するスコープ
     */
    public function scopeActive($query)
    {
        return $query->where('status', CourseStatus::Active);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'attendances')
            ->withPivot('status', 'attended_at')
            ->withTimestamps();
    }

    public function hasCapacity(): bool
    {
        return $this->attendances()->count() < $this->capacity;
    }
}
