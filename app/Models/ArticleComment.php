<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ArticleComment extends Model
{
    use HasFactory;
    const STATUS_RESOLVED = 'Resolved';
    protected $table = 'article_comments';
    protected $fillable = [
        'user_id',
        'file_id',
        'comment',
        'status',
        'parent_id',
    ];
    public $timestamps = true;

    public function parent()
    {
        return $this->belongsTo(ArticleComment::class, 'parent_id');
    }

    // Define the relationship to get the replies of a comment
    public function replies()
    {
        return $this->hasMany(ArticleComment::class, 'parent_id')->with('user');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ArticleComment::class, 'parent_id');
    }

    public static function saveComments($input)
    {
        DB::beginTransaction();
        try {
            $comment = new self;
            $comment->user_id = Auth::user()->id;
            $comment->file_id = $input['file_id'];
            $comment->comment = $input['comment'];
            $comment->start_index = $input['start_index'];
            $comment->comment_id = $input['comment_id'];
            $comment->status = 'Active';
            $comment->save();
            DB::commit();
            $comments = self::getCommentsByFileId($input['file_id']);
            return $comment;

        } catch (\Throwable $th) {
            DB::rollback();
            return false;
        }
    }

    public static function updateInlineComments($id, $input)
    {
        DB::beginTransaction();
        try {
            $comment = self::find($id);
            if (isset($comment) && !empty($comment)) {
                $comment->comment = $input['updatedComment'];
                $comment->update();
            }
            DB::commit();
            $comments = self::getCommentsByFileId($input['file_id']);
            return $comments;
        } catch (\Throwable $th) {
            DB::rollback();
            return false;
        }
    }

    public static function getCommentsByFileId($id, $status = null)
    {
        try {
            DB::beginTransaction();
            $query = self::with(['user', 'replies.user'])
                ->where('file_id', $id);
            // Add sorting by start_index
            $query->orderBy('start_index', 'asc');

            if (!is_null($status)) {
                $query->where('status', $status);
            }

            $comments = $query->get()->map(function ($comment) {
                $comment->formatted_date = $comment->formatted_date;
                $comment->replies->map(function ($reply) {
                    $reply->formatted_date = $reply->formatted_date;
                    return $reply;
                });
                return $comment;
            });

            DB::commit();
            return $comments;
        } catch (\Throwable $th) {
            DB::rollBack();
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function boot()
    {
        parent::boot();
        static::deleting(function ($comment) {
            // Recursively delete child comments
            $comment->children()->each(function ($child) {
                $child->delete();
            });
        });
    }

    public function resolveThread()
    {
        // Mark current comment as resolved
        $this->update(['status' => self::STATUS_RESOLVED]);

        // Recursively resolve child comments
        foreach ($this->children as $child) {
            $child->resolveThread();
        }
    }
}
