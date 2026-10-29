<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class meetingPrompt extends Model
{
    use HasFactory;

    protected $table = 'meeting_prompts'; // Table name
    protected $connection = 'central'; // Specify the central database connection

    protected $fillable = [
        'user_id',
        'prompt',
    ];

    /**
     * Get the user that owns the AI Prompt.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }



}
