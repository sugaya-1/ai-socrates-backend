<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Choice extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function question(): BelongsTo

    {
        return $this -> belongsTo(Question::class);
    }
}
