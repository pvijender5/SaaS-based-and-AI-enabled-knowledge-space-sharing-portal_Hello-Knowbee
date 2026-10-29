<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class searchPrompt extends Model
{
    use HasFactory;

    protected $table = 'search_prompts'; // Table name
    protected $connection = 'central'; // Specify the central database connection

    protected $fillable = [
        'user_id',
        'role_type',
        'content',
    ];

    /**
     * Get the user that owns the AI Prompt.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getPrompts() {
        DB::beginTransaction();
        try {
            $prompts = self::all()->toArray();
            DB::commit();
            return $prompts;
        } catch (\Throwable $th) {
            DB::rollback();
             $th->getMessage();
            return null;
        }
    }

}
