<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;


class SearchHistory extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'search_history';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id', 'search_term'
    ];

    public static function addHistory($search_term)
    {
        try {
            $userId = Auth::user()->id;

            // Check if a record with the same user_id and search_term exists
            $existingRecord = self::where('user_id', $userId)
                                  ->where('search_term', $search_term)
                                  ->first();
    
            if ($existingRecord) {
                // Delete the existing record
                $existingRecord->delete();
            }
    
            // Add the new record
            $data = [
                'user_id' => $userId,
                'search_term' => $search_term
            ];
            self::create($data);
        } catch (\Throwable $th) {
            $response = [
                'message' => $th->getMessage(),
                'statusCode' => '500'
            ];
            return $response;
        }
    }

    public static function fetchHistory($searchTerm)
    {
        try {
            $searchHistory = self::select(['search_term']);
            if(!empty($searchTerm)){
                $searchHistory->where('search_term', 'LIKE', "%{$searchTerm}%");
            }
            $searchHistory->orderBy('created_at', 'desc')->limit(4)->get();
            return $searchHistory;
        } catch (\Throwable $th) {
            $response = [
                'message' => $th->getMessage(),
                'statusCode' => '500'
            ];
            return $response;
        }
    }

    public static function fetchHistorykeyword($text)
    {
        try {
            $userId = Auth::user()->id;
            $cacheKey = "search_history_{$text}";
            return Cache::remember($cacheKey, 600, function () use ($text, $userId) {
                return self::select(['id', 'search_term'])
                    ->where('search_term', 'LIKE', "%{$text}%")
                    ->where('search_term', '!=', $text)
                    ->where('user_id',  $userId)
                    ->orderBy('created_at', 'desc')
                    ->limit(4)
                    ->get();
            });
        } catch (\Throwable $th) {
            $response = [
                'message' => $th->getMessage(),
                'statusCode' => '500'
            ];
            return $response;
        }
    }

        
    public static function deleteSearchHistory($id)
    {
        try {
            DB::beginTransaction();
            $userId = Auth::user()->id;
            $searchHistory = self::where('id', $id)->where('user_id', $userId)->first();
            if (!empty($searchHistory->id)) {
                $searchHistory->delete();
            }
            DB::commit();
            return true;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            $response['status'] = false;
            return $response;
        }
    }

}
