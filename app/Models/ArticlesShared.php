<?php

namespace App\Models;

use App\Models\Space;
use App\Models\User;
use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ArticlesShared extends Model
{
    use HasFactory;

    // Define the table name if it's not the plural form of the model name
    protected $table = 'articles_shared';
    protected $guarded = [];

    // Specify the columns that are mass assignable
    protected $fillable = [
        'article_id',
        'user_id',
        'is_user_author'
    ];

    // If the table has timestamps and they are named 'created_at' and 'updated_at', this is not necessary.
    // If they have different names or are absent, you can customize like this:
    // public $timestamps = false;

    // Define relationships if needed
    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOwner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function space()
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function saveSharedArticlesInfo($request)
    {
        try {
            DB::beginTransaction();
            $articleId = $request->input('articleId');
            $users = $request->input('users'); // Array of user IDs and roles
            foreach ($users as $user) {
                // Check if user already has permission and update or create new permissions
                self::updateOrCreate(
                    ['article_id' => $articleId, 'user_id' => $user['id']]
                );
            }
            DB::commit();
            return true;
        } catch (\Throwable $th) {
            // Return error response
            DB::rollBack();
            return response()->json([
                'message' => $th->getMessage(),
            ], 500);
        }
    }


    public static function giveAccessToEdit($file_id, $authors)
    {
        try {
            DB::beginTransaction();

            // Ensure $file_id and $authors are properly formatted as arrays
            $fileIds = $file_id instanceof \Illuminate\Support\Collection ? $file_id->toArray() : (is_array($file_id) ? $file_id : [$file_id]);

            $userIds = $authors instanceof \Illuminate\Support\Collection ? $authors->toArray() : (is_array($authors) ? $authors : [$authors]);

            // Iterate through each file_id and each user_id
            foreach ($fileIds as $file) {
                foreach ($userIds as $user_id) {
                    ArticlesShared::updateOrCreate(
                        ['article_id' => $file, 'user_id' => $user_id],
                        ['is_user_author' => 1]
                    );
                }
            }

            DB::commit();
            return true;
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::info('giveAccessToEdit', $th->getMessage());
            return response()->json([
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public static function removeEditArticleP($userId,$articleId)
    {
        try {
            DB::beginTransaction();
            self::where('user_id', $userId)
                ->whereIn('article_id', $articleId)
                ->where('is_user_author', 1)
                ->delete();
            DB::commit();

            return true;
        } catch (\Throwable $th) {
            // Return error response
            DB::rollBack();
            return response()->json([
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function getShareArticleUsersList($articleId)
    {
        try {
            DB::beginTransaction();
            $usersWithSharePermissions = ArticlesShared::with('user') // Eager load the user relationship
            ->where('article_id', $articleId)
            ->get()
            ->map(function ($permission) {
                return [
                    'name' => $permission->user->name,
                    'email' => $permission->user->email,
                    'id' => $permission->user->id,
                    'image' => $permission->user->user_image,
                ];
            })
            ->sortBy('name') // Sort by name alphabetically
            ->values(); // Reset keys to avoid gaps

            DB::commit();
            return $usersWithSharePermissions;
        } catch (\Throwable $th) {
            // Return error response
            DB::rollBack();
            return response()->json([
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function removeShareArticlePermissions($articleId, $userId)
    {
        try {
            DB::beginTransaction();
            $data = self::where('user_id', $userId)
                ->where('article_id', $articleId)
                ->delete();
            DB::commit();

            $userListAfterDeletion = self::getShareArticleUsersList($articleId);

            return $userListAfterDeletion;
        } catch (\Throwable $th) {
            // Return error response
            DB::rollBack();
            return response()->json([
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function hasArticleEditPermission($articleId, $userId)
    {
        try {
            DB::beginTransaction();
            $permission = self::where('article_id', $articleId)
                ->where('user_id', $userId)
                ->first();

            DB::commit();
            return $permission;
        } catch (\Throwable $th) {
            // Return error response
            DB::rollBack();
            return response()->json([
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function getauthors($articleId)
    {
        try {
            DB::beginTransaction();
            $permission = self::with(['user:id,firstname,lastname'])
            ->where('article_id', $articleId)
            ->get();
            DB::commit();
            return $permission;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public static function getUserSharedArticle()
    {
        try {
            DB::beginTransaction();
            $userId = Auth::user()->id;
            $articleId = self::select(['article_id'])->where('user_id', $userId)->get()->toArray();
            DB::commit();
            $ids = array_column($articleId, 'article_id');
            return  $ids;
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => $th->getMessage(),
            ], 500);
        }
    }

}
