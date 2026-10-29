<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Models\User;
use App\Models\Articles;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecentViewedArticles extends Model
{
    use HasFactory, Notifiable;
    protected $table = 'recent_viewed_articles';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id', 'file_id'
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function article()
    {
        return $this->belongsTo(Articles::class, 'file_id', 'file_id');
    }

    public static function saveRecentView($fileId)
    {
        DB::beginTransaction();
        try {
            $userId = Auth::user()->id;

            // If there are already 4 recent views, delete the oldest one
            $checkIfExist = self::where('file_id', $fileId)->where('user_id', $userId)->first();
            if($checkIfExist){
                $checkIfExist->delete();
            }
            $recentViewsCount = self::where('file_id', $fileId)->where('user_id', $userId)->count();

            if ($recentViewsCount >= 4) {
                self::where('user_id', $userId)
                    ->orderBy('created_at', 'asc')
                    ->first()
                    ->delete();
            }

            // Insert new recent view
            $article = new self();
            $article->file_id = $fileId;
            $article->user_id = $userId;
            $article->save();
            DB::commit();
            return true;
        } catch (\Throwable $th) {
            DB::rollBack();
            return ['message' => $th->getMessage(), 'statusCode' => 500];
        }
    }

    public static function fetchData()
    {
        try {
            $userId = Auth::user()->id;

            $searchHistory = self::select('recent_viewed_articles.*', 'my_files.title','my_files.status','my_files.encrypt_id')
            ->join('my_files', 'recent_viewed_articles.file_id', '=', 'my_files.encrypt_id') // Adjust the join condition as needed
            ->where('recent_viewed_articles.user_id', $userId)
            ->orderBy('recent_viewed_articles.created_at', 'desc')
            ->limit(4)
            ->get();

            return $searchHistory;
        } catch (\Throwable $th) {
            $response = [
                'message' => $th->getMessage(),
                'statusCode' => '500'
            ];
            return $response;
        }
    }
}
