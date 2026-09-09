<?php

namespace App\Models;

use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facility extends Model
{
    /** @use HasFactory<FacilityFactory> */
    use HasFactory, softDeletes;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'type',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
