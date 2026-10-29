<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use App\Models\MyFiles;
use App\Models\User;
use App\Models\ModerationComment;
use App\Models\Notification;
use App\Helpers\Helper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Jfcherng\Diff\DiffHelper;
use Overtrue\LaravelVersionable\Versionable;
use Overtrue\LaravelVersionable\Version;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Articles extends Model
{
    use HasFactory, Notifiable, Versionable, SoftDeletes;
    protected $table = 'articles';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id', 'contents', 'owner_id', 'file_id', 'title', 'description', 'type', 'status', 'space_id','updated_without_version'
    ];

    protected $versionable = ['title', 'description', 'contents'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function space()
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
    // public function latestVersion()
    // {
    //     return $this->hasOne(Version::class, 'file_id')->latest();
    // }
    public static function saveFlag($id) {
        DB::beginTransaction();

        // Fetch the article using Eloquent without triggering versionable
        $article = Articles::withoutEvents(function() use ($id) {
            return Articles::where('file_id', $id)->first();
        });

        if ($article) {
            $article->updated_without_version = 0;
            $article->save();
        }

        DB::commit();
    }
    public static function saveOrUpdateFileContent($inputData, $notify = null)
    {
        DB::beginTransaction();
        try {
            $userId = Auth::user();
            $owner_id = MyFiles::select(columns: ['id', 'owner_id', 'space_id'])->where('encrypt_id', $inputData['file_id'])->first();
            $article = Articles::where('file_id', $inputData['file_id'])->first();
            $fileId = $inputData['file_id'];

            // Helper function to check if content is already encrypted
            $isEncrypted = function($content) {
                try {
                    $decrypted = Helper::encryptDecryptId($content, 'd');
                    return $decrypted !== false && $decrypted !== $content;
                } catch (\Exception $e) {
                    return false;
                }
            };

            // Process content - encrypt only if not already encrypted
            $content = $inputData['content'];
            if (!$isEncrypted($content)) {
                $content = Helper::encryptDecryptId($content, 'e');
            }

            if ($article) {
                $article->fill([
                    'title' => $inputData['title'],
                    'description' => $inputData['description'] . " ",
                    'contents' => $content . '<br/>',
                    'user_id' => $inputData['user_id'],
                    'space_id' => $inputData['space_id'],
                    'type' => 'Article',
                    'updated_without_version' => 0,
                    'status' => 'Published',
                ]);
                $type = 'article';
                $article->save();
                $data = static::publishLatestVersion($article->id, $fileId);
                $myFiles = MyFiles::where('encrypt_id', $fileId)->first();
                $myFiles->update([
                    'contents' => $article->contents,
                    'space_id' => $inputData['space_id'],
                    'status' => 'Published',
                    'status_comments' => NULL
                ]);
            } else {
                $article = new Articles();
                $article->title = $inputData['title'];
                $article->file_id = $fileId;
                $article->user_id = $inputData['user_id'];
                $article->owner_id = $owner_id->owner_id;
                $article->space_id = $inputData['space_id'];
                $article->contents = $content;
                $article->description = $inputData['description'];
                $article->type = 'Article';
                $article->status = 'Published';
                $article->save();

                $data = static::publishLatestVersion($article->id, $fileId);
                $myFiles = MyFiles::where('encrypt_id', $fileId)->first();
                $myFiles->update([
                    'contents' => $article->contents,
                    'space_id' => $inputData['space_id'],
                    'status' => 'Published',
                    'status_comments' => NULL
                ]);
            }
            $owner_id = MyFiles::select(columns: ['id', 'owner_id', 'space_id'])->where('encrypt_id', $inputData['file_id'])->first();
            $spaceName = Space::where('id', $owner_id->space_id)->pluck('name')->first();
            // dd($spaceName);
            $notify_users = SpaceUser::orWhere('space_id', $owner_id->space_id)
            ->orWhere('user_id', '!=', $userId->id)
            ->distinct()
            ->pluck('user_id')
            ->toArray();
            $notification = '<b>Published </b>' . $article->title . ' in ' . $spaceName;
            if (!empty($notify_users) && $notify == null) {
                $sender_id = $userId->id;
                $type = 'article';
                Notification::sendWebNotification($sender_id, $notify_users, $type, $notification);
            }
            DB::commit();
            // Manually trigger the update function from the observer
            $observer = new \App\Observers\ArticlesObserver();
            $observer->syncToElasticsearch($article);
            return $data;
        } catch (\Throwable $th) {
            //dd($th->getMessage());
            Log::error('savng article: ' . $th->getMessage());

            return ['message' => $th->getMessage(), 'statusCode' => 500];
        }
    }

    public static function unPublishedArticle($articleId, $notify = null)
    {
        try {
            DB::beginTransaction();
            $userId = Auth::user();
            // Fetch the article by file_id
            $article = Articles::where('file_id', operator: $articleId)->first();
            if ($article) {
                $article->update(['status' => 'Draft']); // Use an associative array
            }

            // Fetch the file by encrypt_id
            $myFiles = MyFiles::where('encrypt_id', $articleId)->first();
            if ($myFiles) {
                $myFiles->update([
                    'status' => 'Draft',
                    'is_shared_externally' => '1',

                ]); // Use an associative array
            }
            $versions = $article->versions()->orderBy('created_at', 'desc')->get();
            $latestVersion = $versions->first();
            DB::transaction(function () use ($versions, $latestVersion) {
                $latestVersion->update(['status' => 'Unpublished']);
            });

            $owner_id = MyFiles::select(columns: ['id', 'owner_id', 'space_id'])->where('encrypt_id', $articleId)->first();
            $notify_users = SpaceUser::orWhere('space_id', $owner_id->space_id)
            ->orWhere('user_id', '!=', $userId->id)
            ->distinct()
            ->pluck('user_id')
            ->toArray();
            $notification = '<b>Unpublished </b>' . $article->title . ' by ' . $userId->name;
            if (!empty($notify_users) && $notify == null) {
                $sender_id = $userId->id;
                $type = 'article';
                Notification::sendWebNotification($sender_id, $notify_users, $type, $notification);
            }
            DB::commit(); // Commit the transaction

            // Manually trigger the delete function from the observer
            $observer = new \App\Observers\ArticlesObserver();
            $observer->deleteFromElasticsearch($article);

            return true;
        } catch (\Exception $e) {
            //dd($e->getMessage());
            DB::rollBack(); // Rollback the transaction on error
            return response()->json([
                'message' => 'Failed to unpublish the article and draft others.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public static function updateAccessSettings($data)
    {
        try {
            DB::beginTransaction();

            $myFiles = MyFiles::where('encrypt_id', $data['file_id'])->first();
            if ($myFiles) {
                $myFiles->update([
                    'edit_scope' => $data['access_id'],
                ]);
            }
            DB::commit(); // Commit the transaction

            return true;
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback the transaction on error
            return response()->json([
                'message' => 'Failed to unpublish the article and draft others.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public static function publishLatestVersion($articleId, $fileId)
    {
        try {
            DB::beginTransaction();
            $article = Articles::findOrFail($articleId);
            $versions = $article->versions()->orderBy('created_at', 'desc')->get();
            if ($versions->isEmpty()) {
                return response()->json([
                    'message' => 'No versions found for this article.',
                ], 404);
            }

            $latestVersion = $versions->first();
            DB::transaction(function () use ($versions, $latestVersion) {
                $latestVersion->update(['status' => 'Published']);
                foreach ($versions->slice(1) as $version) {
                    $version->update(['status' => 'Draft']);
                }
            });

            $formattedVersions = static::getVersions($fileId);
            DB::commit();
            return $formattedVersions;

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to publish the latest version and draft others.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public static function getFileContentDetails($id)
    {
        try {
            DB::beginTransaction();
            $file = Articles::where('file_id', $id)->first();

            if ($file) {
                // Helper function to check if content is already encrypted
                $isEncrypted = function($content) {
                    try {
                        $decrypted = Helper::encryptDecryptId($content, 'd');
                        return $decrypted !== false && $decrypted !== $content;
                    } catch (\Exception $e) {
                        return false;
                    }
                };

                // Only decrypt if content is encrypted
                if ($isEncrypted($file->contents)) {
                    $file->contents = Helper::encryptDecryptId($file->contents, 'd');
                }
                return $file;
            } else {
                $file = MyFiles::where('encrypt_id', $id)->first();
                if ($file) {
                    // Helper function to check if content is already encrypted
                    $isEncrypted = function($content) {
                        try {
                            $decrypted = Helper::encryptDecryptId($content, 'd');
                            return $decrypted !== false && $decrypted !== $content;
                        } catch (\Exception $e) {
                            return false;
                        }
                    };

                    // Only decrypt if content is encrypted
                    if ($isEncrypted($file->contents)) {
                        $file->contents = Helper::encryptDecryptId($file->contents, 'd');
                    }
                }
                return $file;
            }
            DB::commit();
        } catch (\Throwable $th) {
            return ['message' => $th->getMessage(), 'statusCode' => 500];
        }
    }

    public static function getInReviewArticles()
    {
        try {
            DB::beginTransaction();

            $file = MyFiles::select(['title','id'])->where('status', 'pending_approval')->get();
            return $file;

            DB::commit();
        } catch (\Throwable $th) {
            return ['message' => $th->getMessage(), 'statusCode' => 500];
        }
    }

    public static function fetchContentDetailsFromPublishedVersion($id)
    {
        try {
            DB::beginTransaction();

            // Helper function to check if content is already encrypted
            $isEncrypted = function($content) {
                try {
                    $decrypted = Helper::encryptDecryptId($content, 'd');
                    return $decrypted !== false && $decrypted !== $content;
                } catch (\Exception $e) {
                    return false;
                }
            };

            // Attempt to find the article by file_id
            $file = Articles::select(['id','title' ])->where('file_id', $id)->where('status', 'Published')->first();

            if (!$file) {
                // Fallback to MyFiles if no article is found
                $file = MyFiles::select(['contents','title'])->where('encrypt_id', $id)->first();
                if ($file) {
                    // Only decrypt if content is encrypted
                    if ($isEncrypted($file->contents)) {
                        $file->contents = Helper::encryptDecryptId($file->contents, 'd');
                    }
                }
                DB::commit(); // Commit the transaction before returning
                return $file;
            }

            // Check if the article has any versions
            $versions = $file->versions;

            if (!$versions || $versions->isEmpty()) {
                // Fallback to MyFiles if no versions are found
                $file = MyFiles::select(['contents','title'])->where('encrypt_id', $id)->first();
                if ($file) {
                    // Only decrypt if content is encrypted
                    if ($isEncrypted($file->contents)) {
                        $file->contents = Helper::encryptDecryptId($file->contents, 'd');
                    }
                }
                DB::commit(); // Commit the transaction before returning
                return $file;
            }

            // Retrieve the latest published version
            $newVersion = $file->latestPublishedVersion();

            if (!$newVersion) {
                // No published version exists
                DB::rollBack(); // Rollback if no valid version is found
                return ['message' => 'No published version found.', 'statusCode' => 404];
            }

            // Only decrypt if content is encrypted
            $content = $newVersion->contents;
            if ($isEncrypted($content)) {
                $content = Helper::encryptDecryptId($content, 'd');
            }

            // Return the latest published version's contents
            DB::commit();
            return [
                'contents' => $content,
                'title' => $file->title,
                'statusCode' => 200
            ];
        } catch (\Throwable $th) {
            DB::rollBack(); // Ensure the transaction is rolled back on failure
            return ['message' => $th->getMessage(), 'statusCode' => 500];
        }
    }


    public function restoreToVersion($versionable_id, $versionId, $fileId)
    {
        try {
            DB::beginTransaction();
            $article = Articles::findOrFail($versionable_id);
            MyFiles::where('encrypt_id', $fileId)->update(['status' => 'Published']);
            $version = DB::table('versions')->where('id', $versionId)->first();
            if (!$version) {
                return response()->json([
                    'message' => 'Version not found',
                ], 404);
            }

            $contents = json_decode($version->contents, true);
            //dd($contents);
            $article->update(['contents' => $contents['contents']]);

            $latestVersionId = $article->versions()->latest()->value('id');
            // $article::withoutVersion(function () use ($article, $contents) {
            //     if (isset($contents['contents'])) {
            //     }
            // });
            $versionIds = DB::table('versions')
            ->where('versionable_id', $versionable_id)
            ->where('id', '<=', $versionId) // Filter only versions where ID is <= 10
            ->orderBy('created_at', 'asc')
            ->pluck('id')
            ->toArray();

            $versionIndex =  count($versionIds);

            DB::table('versions')
            ->where('id', $latestVersionId)
            ->update(['comment' => 'Restored from a prior version V'.$versionIndex.'.0']);

            DB::table('versions')
                ->where('versionable_id', $versionable_id)
                ->update([
                    'status' => DB::raw("CASE WHEN id = $latestVersionId THEN 'Published' ELSE 'Draft' END"),
                ]);

            $versions = static::getVersions($fileId); // show version in response
            DB::commit();
            return $versions;

        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to restore article to version',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function showVersionContent($versionId)
    {
        try {
            DB::beginTransaction();
            $version = DB::table('versions')->where('id', $versionId)->first();
            if (!$version) {
                return response()->json([
                    'message' => 'Version not found',
                ], 404);
            }
            $contents = json_decode($version->contents, true);
            
            // Decrypt the content before returning
            if (isset($contents['contents'])) {
                $contents['contents'] = Helper::encryptDecryptId($contents['contents'], 'd');
            }

            DB::commit();
            return $contents;

        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to show version content',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
    public static function getVersions($id)
    {
        try {
            DB::beginTransaction();
            $file_exists = Articles::where('file_id', $id)->first();
            $response = [];
            if ($file_exists) {
                $article = Articles::findOrFail($file_exists['id']);

                // Retrieve versions with pagination
                $versions = $article->versions()
                    ->orderBy('created_at', 'desc')
                    ->get();
                $versionIds = $versions->pluck('id')->toArray();

                $moderationComments = ModerationComment::with('user')
                    ->whereIn('version_id', $versionIds)
                    ->orderBy('created_at','desc')
                    ->get()
                    ->groupBy('version_id');
                $formattedVersions = $versions->filter(function ($version) {
                    return isset($version->contents['contents']);
                })->map(function ($version, $index) use ($article, $versions, $moderationComments) {
                    $user = User::find($version['user_id']);
                    // Decrypt the content for each version
                    $decryptedContent = Helper::encryptDecryptId($version->contents['contents'], 'd');

                    $commentsForVersion = $moderationComments->get($version->id, collect());

                    return [
                        'version_id' => $version->id,
                        'versionable_id' => $version->versionable_id,
                        'content' => $decryptedContent ?? null,
                        'version_comment' => $version->comment ?? null,
                        'title' => $article['title'],
                        'description' => $version->contents['description'] ?? null,
                        'created_at' => $version->created_at->format('F j, Y, g:i:s A'),
                        'user' => $user['name'],
                        'status' => $version['status'],
                        'versionIndex' => "V" . ($versions->count() - $index),
                        'lastVersion' => count($versions),
                        'moderationComments' => $commentsForVersion,
                        'list' => $versions,
                    ];
                });

                $response['paginate'] = $versions;
                $response['formattedVersions'] = $formattedVersions;

                DB::commit();
                return $response;
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to restore article to version',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
    // public static function getVersions($id) {
    //     try {
    //         DB::beginTransaction();
    //         $file_exists = Articles::where('file_id', $id)->first();
    //         $response = [];
    //         if($file_exists) {
    //             $article = Articles::findOrFail($file_exists['id']);
    //             $versions = $article->versions()->orderBy('created_at', 'desc')->paginate(10);
    //             $formattedVersions = $versions->filter(function ($version) {
    //                 return isset($version->contents['contents']);
    //             })->map(function ($version, $index) use ($article, $versions) {
    //                 $user = User::find($version['user_id']);
    //                 return [
    //                     'version_id' => $version->id,
    //                     'versionable_id' => $version->versionable_id,
    //                     'content' => $version->contents['contents'] ?? null,
    //                     'title' => $article['title'],
    //                     'description' => $version->contents['description'] ?? null,
    //                     'created_at' => $version->created_at->format('F j, Y, g:i:s A'),
    //                     'user' => $user['name'],
    //                     'status' => $version['status'],
    //                     'versionIndex' => "V-" . ($versions->count() - $index),
    //                     'lastVersion' => count($versions),
    //                     'list' => $versions
    //                 ];
    //             });
    //             $response['paginate'] = $versions;
    //             $response['formattedVersions'] = $formattedVersions;
    //             DB::commit();
    //             return $response;
    //         }
    // }
    // catch (\Throwable $th) {
    //     return response()->json([
    //         'message' => 'Failed to restore article to version',
    //         'error' => $th->getMessage(),
    //     ], 500);
    // }
    // }

    public static function deleteArticle($id)
    {
        try {
            DB::beginTransaction();
            $fileId = Helper::encryptDecryptId($id);
            $articlegetid = Articles::where('file_id', $fileId)->first();
            if (!empty($articlegetid->id)) { // this method because oberver not working.
                $article = Articles::findOrFail($articlegetid->id); // Find article by ID
                $articleTitle = $article->title;
                $article->delete();
            }
            $sender = Auth::user();
            $nameParts = explode(' ', trim($sender->name));
            $firstName = ucfirst(strtolower($nameParts[0]));
            $lastNameInitial = isset($nameParts[1]) ? strtoupper(substr($nameParts[1], 0, 1)) . '.' : '';
            $formattedSenderName = $firstName . ' ' . $lastNameInitial;
            $type = 'article';
            $notification = "<b>Deleted</b> " . $articleTitle . "by " . $formattedSenderName;
            $notifyUsers = ArticlesShared::where('article_id', $articlegetid->file_id)
            ->where('user_id', '!=', $sender->id)
            ->pluck('user_id')
            ->toArray();
            if (!empty($notifyUsers)) {
                Notification::sendWebNotification($sender->id, $notifyUsers, $type, $notification);
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

    public static function getVersionsDifference($article_id, $version_id)
    {
        try {
            DB::beginTransaction();
            $article = Articles::where('file_id', $article_id)->first();
            $newVersion = $article->latestPublishedVersion();
            $oldVersion = $article->versions()->findOrFail($version_id);
            if (!$newVersion || !$oldVersion) {
                abort(404, 'Article version not found');
            }
            if (isset($oldVersion['contents']['contents']) && isset($newVersion['contents']['contents'])) {
                $data1 = $newVersion['contents']['contents'];
                $data2 = $oldVersion['contents']['contents'];
                $diffOptions = [
                    'context' => 3,
                    'ignoreCase' => true,
                    'ignoreLineEnding' => true,
                    'ignoreWhitespace' => true,
                    'fullContextIfIdentical' => true,
                ];
                $rendererOptions = [
                    'detailLevel' => 'line',
                    'lineNumbers' => true,
                    'separateBlock' => true,
                    'showHeader' => true,
                    'wordGlues' => [' ', '-'],
                    'resultForIdenticals' => 'There is no difference',
                    'wrapperClasses' => ['diff-wrapper'],
                ];
                $diff = DiffHelper::calculate($data2, $data1, 'SideBySide', $diffOptions, $rendererOptions);
                DB::commit();
                return response()->json(['diff' => $diff]);
            }

        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            $response['status'] = false;
            return $response;
        }
    }
    public function latestPublishedVersion()
    {
        DB::beginTransaction();
        return $this->versions()->where('status', 'Published')->latest()->first();
        DB::commit();
    }

    // public function getSharedWithMeFiles()
    // {
    //     try {
    //         $userId = Auth::user()->id;
    //         $files = DB::table('articles_shared')
    //             ->join('my_files', 'articles_shared.article_id', '=', 'my_files.encrypt_id') // Join with articles
    //             ->join('users', 'my_files.owner_id', '=', 'users.id') // Join with users to get owner details
    //             ->join('spaces', 'my_files.space_id', '=', 'spaces.id') // Join with users to get owner details
    //             ->where('articles_shared.user_id', $userId)
    //             ->whereNull('my_files.deleted_at')
    //             ->select(
    //                 'articles_shared.*',
    //                 'my_files.title', // Specific field from my_files
    //                 'my_files.status',
    //                 'my_files.type',
    //                 'users.name as owner_name',
    //                 'spaces.name as space_name',
    //             )->paginate(10);

    //         return $files;
    //     } catch (\Throwable $th) {
    //         $response['message'] = $th->getMessage();
    //         return $response;
    //     }
    // }

    public function getSharedWithMeFiles($page, $searchTerm, $searchStatus, $searchSpace, $searchUser)
    {
        try {
            $userId = Auth::user()->id;

            // Start building the query
            $query = DB::table('articles_shared')
                ->join('my_files', 'articles_shared.article_id', '=', 'my_files.encrypt_id') // Join with my_files
                ->leftJoin('users', 'my_files.owner_id', '=', 'users.id') // Left join with users to get owner details
                ->leftJoin('spaces', 'my_files.space_id', '=', 'spaces.id') // Left join with spaces to get space details
                ->where('articles_shared.user_id', $userId)
                ->whereNull('my_files.deleted_at');

            // Add search conditions dynamically
            if (!empty($searchTerm)) {
                $query->where('my_files.title', 'like', '%' . $searchTerm . '%');
            }

            if (!empty($searchStatus)) {
                $query->where('my_files.status', $searchStatus);
            }

            if (!empty($searchSpace)) {
                $query->where('my_files.space_id', $searchSpace);
            }

            if (!empty($searchUser)) {
                $query->where('my_files.owner_id', $searchUser);
            }

            // Execute the query and paginate the results
            $files = $query->select(
                'articles_shared.*',
                'my_files.title',
                'my_files.status',
                'my_files.type',
                'my_files.space_id', // Space ID for later use
                'my_files.owner_id', // Owner ID for later use
                'users.name as owner_name',
                'spaces.name as space_name',
                'spaces.image as space_image'
            )->paginate(10);

            // Collect unique space IDs and user IDs from the shared files
            $spaceIds = $files->pluck('space_id')->filter()->unique()->toArray(); // Filter out null values
            $userIds = $files->pluck('owner_id')->filter()->unique()->toArray(); // Filter out null values

            // Fetch spaces details
            $spaces = Space::whereIn('id', $spaceIds)->select('id', 'name', 'image')->get();
            $spaceList = $spaces->map(function ($space) {
                return [
                    'id' => $space->id,
                    'name' => $space->name,
                    'image' => $space->image,
                ];
            });

            // Fetch user details
            $users = User::whereIn('id', $userIds)->select('id', 'name', 'email')->get();
            $userList = $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ];
            });

            // Return files along with the list of unique spaces and users
            return [
                'listing' => $files,     // Paginated list of shared files
                'spaces' => $spaceList, // List of unique spaces with id and name
                'users' => $userList,   // List of unique users with id and name
            ];
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            return $response;
        }
    }

    public static function getLatestVersionContent($articleId) {
       try {

            DB::beginTransaction();
            $article =  Articles::where('file_id', $articleId)->first();
           // dd($article);
            $versions = $article->versions()->where(['status' => 'Published'])->orderBy('created_at', 'desc')->get();
            if ($versions->isEmpty()) {
                return response()->json([
                    'message' => 'No versions found for this article.',
                ], 404);
            }

          //  dd($article);
            $latestVersion = $versions->first();
            //dd($latestVersion);
            $content = $latestVersion->contents;
            DB::commit();
            return $content;

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to publish the latest version and draft others.',
                'error' => $e->getMessage(),
            ], 500);
        }

    }

    public static function getPublishedCount() {
       try {

            DB::beginTransaction();
            $articlecount =  Articles::where('status', 'Published')->whereNull('deleted_at')->count();
            DB::commit();
            return $articlecount;

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to publish the latest version and draft others.',
                'error' => $e->getMessage(),
            ], 500);
        }

    }

    public static function saveVersionNote($versionId,$data) {
        try {
            DB::beginTransaction();
            $version = Version::find($versionId);
            if (!$version) {
                return response()->json(['message' => 'Version not found'], 404);
            }
            if ($version) {
                // Directly access the contents since it's already an array
                $contents = $version->contents;  // This is already an array

                // Update the description field
                $contents['description'] = $data['notes'];  // New value for description

                // Save the updated contents back (it will be automatically encoded to JSON)
                $version->contents = $contents;
                $version->save();
                DB::commit();
                return $version;
            }
        }
        catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to publish the latest version and draft others.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
