<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Bed extends Model
{
    protected $fillable = [
        'room_id',
        'bed_number',
        'patient_id',
        'status'
    ];

    const STATUSES = ['available', 'occupied', 'maintenance'];

    protected static function boot()
    {
        parent::boot();

        // Before saving, update status based on patient_id
        static::saving(function ($bed) {
            // If patient_id is being changed
            if ($bed->isDirty('patient_id')) {
                // If patient_id is set and status isn't maintenance
                if ($bed->patient_id !== null && $bed->status !== 'maintenance') {
                    $bed->status = 'occupied';
                }
                // If patient_id is null and status is occupied
                elseif ($bed->patient_id === null && $bed->status === 'occupied') {
                    $bed->status = 'available';
                }
            }
        });
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function isOccupied(): bool
    {
        return $this->status === 'occupied';
    }

    public function isInMaintenance(): bool
    {
        return $this->status === 'maintenance';
    }

    /**
     * Get count of occupied beds
     */
    public static function getOccupiedBedsCount()
    {
        return self::whereNotNull('patient_id')->count();
    }
} 