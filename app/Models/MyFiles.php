<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Notifications\Notifiable;
use App\Models\User;
use App\Models\Space;
use App\Models\PostAttachment;
use App\Models\Articles;
use App\Models\SpaceUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Traits\Common;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Html;
use App\Helpers\Helper;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MyFiles extends Model
{
    use HasFactory, SoftDeletes, Notifiable, Common;
    protected $table = 'my_files';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'owner_id', 'encrypt_id', 'is_shared_externally', 'edit_scope', 'space_id', 'folder_id', 'title','description','type','status','archived_date','status_comments',
        'updated_without_version'
    ];

    public function user(){
        return $this->belongsTo(User::class, 'owner_id');
    }
    public function space(){
        return $this->belongsTo(Space::class, 'space_id');
    }
    public function spaceUsers()
    {
        return $this->hasMany(SpaceUser::class, 'space_id');
    }
    public function likes()
    {
        return $this->hasMany(PostLike::class,'file_id');
    }
    public function comments()
    {
        return $this->hasMany(PostComment::class,'file_id');
    }
    public function post_attachments()
    {
        return $this->hasMany(PostAttachment::class,'file_id');
    }
    public function moderation()
    {
        return $this->hasMany(Moderation::class,'file_id','encrypt_id');
    }
    public function articles()
    {
        return $this->hasOne(Articles::class,'file_id','encrypt_id');
    }
    public function articlesShared() {
        return $this->hasMany(ArticlesShared::class, 'article_id', 'encrypt_id');
    }
    public static  function createFile($input){
        try {
            DB::beginTransaction();
            //$userId = Auth::user()->id;
            // $existingFilesCount = MyFiles::where('title', 'like', $title . '%')->where('owner_id', $userId)->count();

            // if ($existingFilesCount > 0) {
            //     $title = $baseTitle . '-' . sprintf('%02d', $existingFilesCount);
            // }else{
            //     $title = 'untitled-01';
            // }

            $title = 'untitled';
            $file = new MyFiles();
            $file->title = $title;
            $file->owner_id = $input['user_id'];
            if(isset($input['folder_id'])){
                $file->folder_id = $input['folder_id'];
            }
            //$file->space_id = $input['space_id'];
            $file->type = 'Article';
            $file->status = 'Draft';

            if (!$file->save()) {
                throw new Exception('Failed to save file');
            }
            DB::commit();
            return $file;

        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static  function createArticleMeeting($input){
        try {
            DB::beginTransaction();
            $userId = Auth::user()->id;

            $file = new MyFiles();
            $file->title = $input['title'];
            $file->owner_id = $userId;

            if(isset($input['folder_id'])){
                $file->folder_id = $input['folder_id'];
            }

            $file->type = 'Article';
            $file->status = 'Draft';

            // Ensure the content is properly formatted HTML
            $file->contents = $input['article'];

            if (!$file->save()) {
                throw new Exception('Failed to save file');
            }

            DB::commit();
            $encryptId = Helper::encryptDecryptId($file->id);
            $updation = [];
            $updation['id'] = $file->id;
            $updation['encrypt_id'] = $encryptId;
            self::updateEncrypt($updation);
            return $encryptId;

        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public static  function createOpenAIArticle($input){
        try {
            DB::beginTransaction();

            $title = $input['title'];
            $userId = Auth::user()->id;
            $file = new MyFiles();
            $file->title = $title;
            $file->owner_id = $userId;
            $file->contents = $input['content'];
            $file->type = 'Article';
            $file->status = 'Draft';
            if (!$file->save()) {
                throw new Exception('Failed to save file');
            }
            DB::commit();
            $encryptId = Helper::encryptDecryptId($file->id);
            $updation = [];
            $updation['id'] = $file->id;
            $updation['encrypt_id'] = $encryptId;
            self::updateEncrypt($updation);
            return $encryptId;

        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static  function createArticleFromTemplate($input){
        try {
            DB::beginTransaction();

            $title = $input['template_name'];
            $userId = Auth::user()->id;
            $file = new MyFiles();
            $file->title = $title;
            $file->owner_id = $userId;
            $file->contents = $input['template_content'];
            $file->type = 'Article';
            $file->status = 'Draft';
            if (!$file->save()) {
                throw new Exception('Failed to save file');
            }
            DB::commit();
            $encryptId = Helper::encryptDecryptId($file->id);
            $updation = [];
            $updation['id'] = $file->id;
            $updation['encrypt_id'] = $encryptId;
            self::updateEncrypt($updation);
            return $encryptId;

        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }
    public static function updateContent($inputData)
    {
        try {
            DB::beginTransaction();
            $file_id = Helper::encryptDecryptId($inputData['file_id'], 'd');
            $article = MyFiles::findOrFail($file_id);

            if ($article) {
                // First encrypt the content before saving
                $inputData['content'] = Helper::encryptDecryptId($inputData['content'], 'e');
                // $decrypted = Helper::encryptDecryptId($inputData['content'], 'd');
                // dd([
                //     'original' => $inputData['content'],
                //     'encrypted' => $inputData['content'],
                //     'decrypted' => $decrypted
                // ]);
                $article->contents = $inputData['content'];
                $article->type = 'Article';
                $article->save();

                $updateArticle = Articles::where('file_id', $inputData['file_id'])->first();
                if($updateArticle) {
                    $updateArticle->disableVersioning();
                    $latestVersionContent = Articles::getLatestVersionContent($inputData['file_id']);
                    $html1 = $inputData['content'];
                    $html2 = $latestVersionContent['contents'];

                    // Remove HTML tags from the content
                    $plainText1 = strip_tags($html1);  // Removes all HTML tags from the first string
                    $plainText2 = strip_tags($html2);  // Removes all HTML tags from the second string
                    if($plainText1 !== $plainText2 || (isset($latestVersionContent['title']) && $article->title !== $latestVersionContent['title'])) {
                        $updateArticle->update(['contents' => $inputData['content'],'updated_without_version' => 1]);
                    }
                    else {
                        $updateArticle->update(['contents' => $inputData['content'],'updated_without_version' => 0]);
                    }
                    Articles::withoutVersion(function () use ($article, $inputData, $updateArticle) {
                    $updateArticle->update(['contents' => $inputData['content']]);
                    });
                }
                DB::commit();
                return true;
            }
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public static function updateMyFiles($inputData)
    {
        try {
            DB::beginTransaction();
            $file_id = Helper::encryptDecryptId($inputData['file_id'], 'd');
            $myfile = MyFiles::findOrFail($file_id);

            if ($myfile) {
                $myfile->edit_scope = $inputData['edit_scope'];
                $myfile->is_shared_externally = $inputData['is_shared_externally'] ? 0 : 1;
                $myfile->save();


            }
            if($inputData['edit_scope'] == 2 ){
                $spaceAuthor = Space::getSpaceAuthor($myfile->space_id);
                if(!empty($spaceAuthor)){
                    ArticlesShared::giveAccessToEdit($inputData['file_id'], $spaceAuthor);
                }
            }
            DB::commit();
            return true;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public static  function getFileDetails($id){
        try {
            DB::beginTransaction();
            $file = self::with(['space','user','moderation' => function($query) {
                $query->where('status', 3);
            }])->where('id',$id)->first();
            if ($file && $file->moderation->isNotEmpty()) {
                $file->moderation = $file->moderation->first();
            }
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
            DB::commit();
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public static  function checkPermissionToEdit($articleId){
        try {
            DB::beginTransaction();

            $file =  MyFiles::select(['id','space_id' ])->with(['space'])->where('encrypt_id', $articleId)
            ->where('status', 'Published')
            ->where('edit_scope', 2)
            ->first();

            if ($file) {
                $loggedInUserId = Auth::id();
                $userHasAccess = SpaceUser::where('space_id', $file->space_id)
                    ->where('user_id', $loggedInUserId)
                    ->where('space_role_id', 1)
                    ->exists();
                if ($userHasAccess) {
                    return $file;
                }
            }

            return null; // or handle unauthorized access
            DB::commit();
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static  function getSpaceName($id){
        try {
            DB::beginTransaction();
            $file = self::with(['space'])->where('encrypt_id',$id)->first();
            return $file;
            DB::commit();
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }


    public static function listing($searchTerm, $searchStatus, $searchSpace, $searchType, $searchUser) {
        try {
            DB::beginTransaction();
            $userId = Auth::user()->id;

            $file = self::with(['user', 'space', 'articles', 'articlesShared'])
                ->where('type', '!=', 'Post')
                ->whereNotIn('status', ['Archived'])
                ->where(function ($query) use ($userId) {
                    $query->where('owner_id', $userId)
                        ->orWhereHas('articlesShared', function ($query) use ($userId) {
                            $query->where('user_id', $userId);
                        });
                });

            if (!empty($searchTerm)) {
                $file->where('title', 'like', '%' . $searchTerm . '%');
            }
            if (!is_null($searchStatus) && $searchStatus !== '') {
                $file->where('status', $searchStatus);
            }
            if (!empty($searchSpace)) {
                $file->where('space_id', $searchSpace);
            }
            if (!empty($searchType)) {
                $file->where('type', $searchType);
            }
            if (!empty($searchUser)) {
                $file->where('owner_id', $searchUser);
            }

            $listing = $file->orderBy('updated_at', 'DESC')->paginate(12);

            // Extract unique space names and ids from the result
            $spaces = $listing->pluck('space')->filter()->unique('id')->map(function($space) {
                return [
                    'id' => $space->id,
                    'name' => $space->name,
                    'image' => $space->image,
                ];
            })->values()->all();

            $users = $listing->pluck('user')->filter()->unique('id')->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ];
            })->values()->all();

            DB::commit();

            return [
                'listing' => $listing,
                'spaces' => $spaces,
                'users' => $users
            ];

        } catch (\Throwable $th) {
            DB::rollBack();
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public static  function spaceFiles($searchTerm,$spaceid){
        try {
            DB::beginTransaction();

            $file = self::with(['user'])->where('space_id', $spaceid)->where('type', '!=', 'Post')->whereNotIn('status', ['Archived']);
            if(!empty($searchTerm)) {
                $file->where('title', 'like', '%' . $searchTerm . '%');
            }
            return $file->orderBy('updated_at', 'DESC')
            ->paginate(12);

        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static  function adminFileslisting($searchTerm,$searchStatus,$searchSpace,$searchType,$searchArchived,$searchDeleted,$searchUser){
        try {
            DB::beginTransaction();
            $userId = Auth::user()->id;
            $file = self::with(['user','space','articles'])->where('type', '!=', 'Post');
            if(!empty($searchTerm)) {
                $file->where('title', 'like', '%' . $searchTerm . '%');
            }
            if(!empty($searchStatus)){
                $file->where('status',$searchStatus);
            }
            if(!empty($searchSpace)){
                $file->where('space_id',$searchSpace);
            }
            if(!empty($searchUser)){
                $file->where('owner_id',$searchUser);
            }
            if(!empty($searchType)){
                $file->where('type',$searchType);
            }
            if(!empty($searchArchived)){
                $file->where('status',$searchArchived);
            }
            if(!empty($searchDeleted)){
                $file->withTrashed()->whereNotNull('deleted_at');
            }


            $listing = $file->orderBy('title', 'ASC')
                ->paginate(12);
            // Extract unique space names and ids from the result
            $spaces = $listing->pluck('space')->filter()->unique('id')->map(function($space) {
            return ['id' => $space->id, 'name' => $space->name,'image' => $space->image];
            })->values()->all();

            $users = $listing->pluck('user')->unique('id')->map(function($user) {
                return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
            })->values()->all();

            DB::commit();

            // Return both the listing and the unique spaces
            return [
                'listing' => $listing,
                'spaces' => $spaces,
                'users' => $users
            ];

        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }
    public static  function trashedFileslisting($searchTerm, $searchStatus, $searchSpace, $searchType, $searchUser)
    {
        try {
            DB::beginTransaction();
            $userId = Auth::user()->id;
            $file = self::with(['user', 'space'])->onlyTrashed()->where('type', '!=', 'Post');
            // dd($file);
            $file->where(function($query) use ($userId) {
                $query->where('owner_id', $userId) // If the logged-in user is the owner
                      ->orWhereHas('articlesShared', function($subQuery) use ($userId) { // Or it's shared with the logged-in user
                          $subQuery->where('user_id', $userId); // Assuming shared_with_user_id is the correct column
                      });
            });
            //dd($file);
            if (!empty($searchTerm)) {
                $file->where('title', 'like', '%' . $searchTerm . '%');
            }
            if (!empty($searchStatus)) {
                $file->where('status', $searchStatus);
            }
            if (!empty($searchSpace)) {
                $file->where('space_id', $searchSpace);
            }
            if (!empty($searchUser)) {
                $file->where('owner_id', $searchUser);
            }
            if (!empty($searchType)) {
                $file->where('type', $searchType);
            }

            $listing = $file->orderBy('title', 'ASC')
            ->paginate(12);
            // dd($listing);

            // Extract unique space names and ids from the result
            $spaces = $listing->pluck('space')->filter()->unique('id')->map(function ($space) {
                return ['id' => $space->id, 'name' => $space->name, 'image' => $space->image];
            })->values()->all();

            $users = $listing->pluck('user')->unique('id')->map(function ($user) {
                return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
            })->values()->all();

            DB::commit();

            // Return both the listing and the unique spaces
            return [
                'listing' => $listing,
                'spaces' => $spaces,
                'users' => $users
            ];
        } catch (\Throwable $th) {
            dd($th->getMessage());
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }


    public static  function archiveListing($searchTerm){
        try {
            DB::beginTransaction();
            $file = self::with(['user','space'])->where('status', 'Archived');
            if(!empty($searchTerm)) {
                $file->where('title', 'like', '%' . $searchTerm . '%');
            }

            $listing =  $file->orderBy('title', 'ASC')
                ->paginate(12);
            DB::commit();
            return $listing;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static  function rootFiles(){
        try {
            DB::beginTransaction();
            $file = self::whereNull('folder_id')
            ->orderBy('title', 'ASC')
            ->get();
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static  function folderInFiles($id){
        try {
            DB::beginTransaction();
            $file = self::where('folder_id',$id)
            ->orderBy('title', 'ASC')
            ->get();
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static  function saveArticleName($id,$input){
        try {
            DB::beginTransaction();
            $file = self::find($id);
            if (isset($file) && !empty($file)) {
                $file->title = $input['title'];
                $file->space_id = $input['space_id'];
                $file->save();
            }
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static  function updateEncrypt($input){
        try {
            DB::beginTransaction();
            $file = self::find($input['id']);
            if (isset($file) && !empty($file)) {
                $file->encrypt_id = $input['encrypt_id'];
                $file->save();
            }
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static  function updateFolderEncryptId($input){
        try {
            DB::beginTransaction();
            $file = self::find($input['id']);
            if (isset($file) && !empty($file)) {
                $file->encrypt_id = $input['encrypt_id'];
                $file->save();
            }
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static function deleteFolder($id) {
        try {
            DB::beginTransaction();
            $folders = self::find($id);
            // dd(  $folders );
            $sender = Auth::user();
            $type = 'article';
            // $file = self::find($id);
            // dd( $file);
            if ($folders) {
                $receiver = $folders->owner_id;
                $type = $folders->type;
                $fileTitle = $folders->title;
                $fileId = $folders->encrypt_id;

                if ($receiver) {
                    // dd($receiver,$sender->id);
                    if ($receiver !== $sender->id) {
                        // dd($receiver);

                    $notification = $sender->firstname . ' ' . $sender->lastname . " deleted the ". $type.' '.$fileTitle;

                    Notification::sendWebNotification($sender->id, [$receiver], $type,$notification);
                }
            }
            $folders->delete();
            $saveHistory = FilesDeleteHistory::saveRestoreDeleteHistory($fileId,'delete');
            DB::commit();

          }
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            $response['status'] = false;
            return $response;
        }
    }



    public static function createPost(Request $request){

        try {
            $existingDraft = MyFiles::where(['status' => 'Draft', 'type' => 'Post'])->first();
            if($existingDraft) {
                $existingDraft->title = $request->title;
                $existingDraft->description = $request->description;
                $existingDraft->owner_id = Auth::user()->id;
                $existingDraft->space_id = $request->space_id;
                $existingDraft->type = 'Post';
                $existingDraft->status = 'Published';
                $fileId = $existingDraft->id;
                $existingDraft->save();
            }
            else {
                $post = MyFiles::create([
                    'title' => $request->title,
                    'description' => $request->description,
                    'owner_id' => Auth::user()->id,
                    'space_id' => $request->space_id,
                    'type' => 'Post',
                    'status' => 'Published'
                ]);
                $fileId = $post->id;
            }

            $tenant = tenant();
            $tenantId = $tenant->id;
            $subFolder = 'posts';
            if ($request->hasFile('image')) {
                self::removeImagesIfExists($fileId);
                // $uploadPath = public_path('images/uploads/posts/');
                // if (!file_exists($uploadPath)) {
                //     mkdir($uploadPath, 0777, true);
                //     chmod($uploadPath, 0777);
                // }
                foreach ($request->file('image') as $key => $image) {
                    $file = $image;
                   // Log::info('Uploaded images:', $request->file('image'));

                    $result = self::uploadFileWithSubfolder($tenantId, $subFolder, $file);
                    self::saveFileInDatabase($fileId,$result['filename'],$result['filepath']);
                }
                //$files = self::getTenantFiles($tenantId,$subFolder);
            }
            if ($request->has('linked_articles') && is_array(json_decode($request->linked_articles))) {
                $sequence = 1;
                $linkedArticles = json_decode($request->linked_articles);
                foreach ($linkedArticles as $articleId) {
                    //Log::info('Received linked article:', ['article_id' => $articleId]);
                    if (isset($articleId->value)) {
                        $url = $articleId->value;
                        if (filter_var($url, FILTER_VALIDATE_URL)) {
                            $fileName = $url;
                                PostAttachment::create([
                                    'file_id' => $fileId,
                                    'type' => 'url',
                                    'file_name' => $fileName,
                                    'sequence' => $sequence,
                                ]);
                        }

                    }
                    elseif (isset($articleId->encrypt_id)) {
                        $fileName = $articleId->encrypt_id;
                        PostAttachment::create([
                            'file_id' => $fileId,
                            'type' => 'article',
                            'file_name' => $fileName,
                            'sequence' => $sequence,
                        ]);
                    } else {
                        Log::warning('No valid value or id in linked article');
                    }

                    $sequence++;
                }

                Log::info('Finished processing linked articles.');
            } else {
                Log::warning('No valid linked articles found in the request.');
            }

            return response()->json([
            'message' => 'Post created successfully',
            'status' => true,
            ], 200);
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public static function createDraft(Request $request) {
    try {
        DB::beginTransaction();
        if (!isset($request['title'])) {
            $baseTitle = 'untitled';
            $title = $baseTitle;
            $existingFilesCount = MyFiles::where('title', 'like', $title . '%')->count();
            if ($existingFilesCount > 0) {
                $title = $baseTitle . '-' . sprintf('%02d', $existingFilesCount);
            }
        } else {
            $title = $request['title'];
        }

        $existingDraft =  MyFiles::with(['post_attachments','space:id,name'])
        ->where(['status' => 'Draft', 'type' => 'Post'])
        ->orderBy('created_at', 'desc')
        ->first();

        if($existingDraft) {
            $existingDraft->title = $title;
            $existingDraft->description = isset($request['description']) ? $request['description'] : null;
            $existingDraft->owner_id = Auth::user()->id;
            $existingDraft->space_id = isset($request['space_id']) ? $request['space_id'] : null;
            $existingDraft->type = 'Post';
            $existingDraft->status = 'Draft';
            $fileId = $existingDraft->id;
            $existingDraft->save();
            $file = $existingDraft;
        }
        else {
            $file = new MyFiles();
            $file->title = $title;
            $file->description = isset($request['description']) ? $request['description'] : null;
            $file->owner_id = Auth::user()->id;
            $file->space_id = isset($request['space_id']) ? $request['space_id'] : null;
            $file->type = 'Post';
            $file->status = 'Draft';
            if (!$file->save()) {
                throw new Exception('Failed to save file');
            }
            $fileId = $file->id;
        }

        if ($request->hasFile('image')) {
            self::removeImagesIfExists($fileId);
            // $uploadPath = public_path('images/uploads/posts/');
            // if (!file_exists($uploadPath)) {
            //     mkdir($uploadPath, 0777, true);
            //     chmod($uploadPath, 0777);
            // }
            $tenant = tenant();
            $tenantId = $tenant->id;
            $subFolder = 'posts';
            if($tenantId) {
                foreach ($request->file('image') as $key => $image) {
                    //$image->move($uploadPath, $imageName);
                    $result = self::uploadFileWithSubfolder($tenantId, $subFolder, $image);
                    if($result) {
                        self::saveFileInDatabase($fileId,$result['filename'],$result['filepath']);
                    }
                }
            }
        }
        else {
            self::removeImagesIfExists($fileId);
        }

        DB::commit();
        return $file;
    } catch (\Throwable $th) {
        DB::rollBack();
        $response['message'] = $th->getMessage();
        $response['statusCode'] = '500';
        return $response;
    }
}



    // public static function createDraft($input) {
    //     try {
    //         DB::beginTransaction();
    //         $file = new MyFiles();
    //         if(!isset($input['title'])) {
    //             $baseTitle = 'untitled';
    //             $title = $baseTitle;
    //             $existingFilesCount = MyFiles::where('title', 'like', $title . '%')->count();
    //             if ($existingFilesCount > 0) {
    //                 $title = $baseTitle . '-' . sprintf('%02d', $existingFilesCount);
    //             }
    //         }
    //         else {
    //             $title = $input['title'];
    //         }
    //         $file->title = $title;
    //         $file->description = isset($input['description']) ? $input['description'] : null;
    //         $file->owner_id = Auth::user()->id;
    //         $file->space_id = isset($input['space_id']) ? $input['space_id'] : null;
    //         $file->type = 'Post';
    //         $file->status = 'Draft';

    //         if (!$file->save()) {
    //             throw new Exception('Failed to save file');
    //         }
    //          if ($input->hasFile('image')) {
    //         $uploadPath = public_path('images/uploads/posts/');
    //         if (!file_exists($uploadPath)) {
    //             mkdir($uploadPath, 0777, true);
    //             chmod($uploadPath, 0777);
    //         }
    //         foreach ($input->file('image') as $key => $image) {
    //             $imageName = time() . '-' . uniqid() . '.' . $image->getClientOriginalExtension();
    //             $image->move($uploadPath, $imageName);
    //             PostAttachment::create([
    //                 'file_id' => $file->id,
    //                 'type' => 'image',
    //                 'sequence' => $key,
    //                 'file_name' => $imageName,
    //                 'file_path' => '/images/uploads/posts/' . $imageName,
    //             ]);
    //         }
    //     }
    //         DB::commit();
    //         return $file;
    //     }
    //     catch (\Throwable $th) {
    //         $response['message'] = $th->getMessage();
    //         $response['statusCode'] = '500';
    //         return $response;
    //     }
    // }
    public static function getAllFeeds() {
        try {
            $loggedInUserId = Auth::user()->id;
            // $fileId = 1;

            DB::beginTransaction();
            $user = User::find($loggedInUserId);

            $file = MyFiles::with(['user', 'space', 'likes','comments','post_attachments'])
                        ->whereIn('space_id', $user->subscribedSpaces->pluck('id'))
                        ->where(['status'=>'Published','type' => 'Post'])
                        ->orderBy('created_at','DESC')
                        ->paginate(10);


            foreach ($file as $feed) {
                // dd($feed);
                //dd($feed->post_attachments);
                $feed->likes_count = $feed->likes->count();
                $feed->comments_count = $feed->comments->count();
                $feed->comments = $feed->comments->map(function($comment) {
                    return $comment->user;
                });
                $feed->liked_by_users = $feed->likes->map(function($like) {
                    return $like->user;
                });
                // dd($feed->comments);
                //  $feed->isLiked = $feed->likes->where('user_id', $loggedInUserId)->where('file_id', $fileId)->isNotEmpty();
                $feed->isLiked = $feed->likes->where('user_id', $loggedInUserId)->isNotEmpty();
                foreach ($feed->post_attachments as $attachment) {
                    $myFile = MyFiles::where('encrypt_id', $attachment->file_name)->first();
                    if ($myFile) {
                        $attachment->file_title = $myFile->title;
                    }
                }
            }
            //dd($file);
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            // Handle exception
            return [
                'message' => $th->getMessage(),
                'statusCode' => 500
            ];
        }
    }

    public static function archiveFile($id) {
        try {
            DB::beginTransaction();
            $file = self::find($id);
            if (isset($file) && !empty($file)) {
                $file->status = 'Archived';
                $sender = Auth::user();
                $type = 'article';
                $type = $file->type;
                $receiver = $file->owner_id;
                $fileTitle = $file->title;
                if ($receiver !== $sender->id) {
                $notification = $sender->firstname . ' ' . $sender->lastname . " archive the ". $type.' '.$fileTitle;
                Notification::sendWebNotification($sender->id, [$receiver], $type,$notification);
            }
                $file->save();
            }
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public static function restoreDeletedFile($id) {
        try {
            DB::beginTransaction();

            $file = self::withTrashed()->find($id);
            if (isset($file) && !empty($file)) {
                $fileId = $file->encrypt_id;
                $file->deleted_at = null;
                $file->status = 'Draft';
                $file->save();
                $saveHistory = FilesDeleteHistory::saveRestoreDeleteHistory($fileId,'restore');
            }
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public static function readFile(Request $request) {
        $docxFilePath = $request->file('file')->getPathname();
        $content = self::extractDocxContent($docxFilePath);
        $file = self::createFileFromUpload($content);
        return $file;
    }
//     public static function extractDocxContent($docxFilePath)
// {
//     // Initialize PHPWord Reader
//     $phpWord = \PhpOffice\PhpWord\IOFactory::load($docxFilePath);

//     // Get all paragraphs from the document
//     $allContent = '';
//     foreach ($phpWord->getSections() as $section) {
//         foreach ($section->getElements() as $element) {
//             // Check the element type
//             if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
//                 foreach ($element->getElements() as $subElement) {
//                     if ($subElement instanceof \PhpOffice\PhpWord\Element\Text) {
//                         $allContent .= $subElement->getText();
//                     } elseif ($subElement instanceof \PhpOffice\PhpWord\Element\Image) {
//                         $imageSource = $subElement->getSource();
//                         echo "Found image: $imageSource\n";

//                         // Save the image or process it as needed
//                         $imageData = $subElement->getImageStringData();
//                         $imagePath = 'path/to/save/images/' . basename($imageSource);
//                         file_put_contents($imagePath, $imageData);
//                         echo "Image saved to: $imagePath\n";

//                         $allContent .= "\n[Image: $imagePath]\n";
//                     }
//                 }
//             } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
//                 $allContent .= $element->getText();
//             } elseif ($element instanceof \PhpOffice\PhpWord\Element\Image) {
//                 $imageSource = $element->getSource();
//                 echo "Found image: $imageSource\n";

//                 // Save the image or process it as needed
//                 $imageData = $element->getImageStringData();
//                 $imagePath = 'path/to/save/images/' . basename($imageSource);
//                 file_put_contents($imagePath, $imageData);
//                 echo "Image saved to: $imagePath\n";

//                 $allContent .= "\n[Image: $imagePath]\n";
//             }
//         }
//     }

//     // Optionally, you can save this content to a file or process it further
//     // For example, save to a file
//     // file_put_contents('extracted_content.txt', $allContent);

//     return $allContent;
// }


    public static function extractDocxContent($docxFilePath)
    {
        // Initialize PHPWord Reader
        $phpWord = IOFactory::load($docxFilePath);

        // Get all paragraphs from the document
        $allContent = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                // Check the element type (e.g., \PhpOffice\PhpWord\Element\TextRun, \PhpOffice\PhpWord\Element\Text, etc.)
                if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                    foreach ($element->getElements() as $text) {
                        if ($text instanceof \PhpOffice\PhpWord\Element\Text) {
                            $allContent .= $element->getText();
                        } elseif ($text instanceof \PhpOffice\PhpWord\Element\Image) {
                            $imageSource = $text->getSource();
                            echo "Found image: $imageSource\n";
    // dd("deee");
                            // Save the image or process it as needed
                            $imageData = $text->getImageStringData();

                            // Get image data as base64
                            $imageData = base64_encode($text->getImageStringData());
                            // Determine MIME type based on image extension






                        // Determine MIME type based on image extension
                        $imageExtension = pathinfo($text->getSource(), PATHINFO_EXTENSION);
                        $imageMimeType = 'image/' . $imageExtension; // Example: 'image/jpeg', 'image/png', etc.



                       // dd($imageData);

                            // Format the image source for embedding
                            $imageSrcEmbed = "data:$imageMimeType;base64,$imageData";

                            // Replace image placeholder in content with embedded image
                            $allContent .= "\n<img src=\"$imageSrcEmbed\" />\n";

                             $allContent .= "\n";
                           // $imageData->move(public_path('images/uploads/spaces/postImages'), $imageData);
                           // $imagePath = public_path('images/uploads/spaces/postImages') . basename($imageSource);
                           // file_put_contents($imagePath, $imageData);
                          //  echo "Image saved to: $imagePath\n";

                           // $allContent .= "\n[Image: $imagePath]\n";
                        }
                        //dd("deee");
                        $allContent .= $element->getText();
                    }
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {

                    $allContent .= $element->getText();
                }
                 elseif ($element instanceof Image) {
                // Handle the image element
              //  dd("deee");
                $imageSource = $element->getSource();
                echo "Found image: $imageSource\n";

                // Save the image or process it as needed
                $imageData = $element->getImageStringData();
               // Get image data as base64
               $imageData = base64_encode($element->getImageStringData());
               $imageMimeType = $element->getImageMimeType();

               // Format the image source for embedding
               $imageSrcEmbed = "data:$imageMimeType;base64,$imageData";

               // Replace image placeholder in content with embedded image
               $allContent .= "\n<img src=\"$imageSrcEmbed\" />\n";

                $allContent .= "\n";
                }
        }

        // Optionally, you can save this content to a file or process it further
        // For example, save to a file
      //  file_put_contents('extracted_content.txt', $allContent);

        return $allContent;
    }

    }
    // public static function readDocFile($filePath) {
    //     $fileContent = file_get_contents($filePath);
    //     $lines = explode(chr(0x0D), $fileContent);
    //     $outtext = "";

    //     foreach ($lines as $thisline) {
    //         $pos = strpos($thisline, chr(0x00));
    //         if (($pos !== FALSE) || (strlen($thisline) == 0)) {
    //             // nothing found here...
    //         } else {
    //             $outtext .= $thisline . " ";
    //         }
    //     }
    //     $outtext = preg_replace("/[^a-zA-Z0-9\s\,\.\-\n\r\t@\/\_\(\)]/", "", $outtext);
    //     dd($lines);
    //     return $outtext;
    // }
    public static function readDocFile($filePath)
    {
        $phpWord = IOFactory::load($filePath);

        // Convert document to HTML
        Settings::setOutputEscapingEnabled(true);
        $html = Html::addHtml($phpWord->getSections()[0], false);

        return $html;
    }
    public static function createFileFromUpload($content) {
        try {
            DB::beginTransaction();
            $baseTitle = 'untitled';
            $title = $baseTitle;
            $userId = Auth::user()->id;
            $existingFilesCount = MyFiles::where('title', 'like', $title . '%')->where('owner_id', $userId)->count();

            if ($existingFilesCount > 0) {
                $title = $baseTitle . '-' . sprintf('%02d', $existingFilesCount);
            }

            $file = new MyFiles();
            $file->title = $title;
            $defualtSpace = Space::getDefaultSpace();
            $file->owner_id = Auth::user()->id;
            $file->space_id = $defualtSpace->id;
            $file->type = 'Article';
            $file->status = 'Draft';
            $file->contents = $content;
            //dd($file);
            if (!$file->save()) {
                throw new Exception('Failed to save file');
            }
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }}

    public static function saveDroppedFilesModel($FilesArr) {
        try {
            DB::beginTransaction();

            if(!empty($FilesArr)){
                foreach ($FilesArr as $key => $value) {

                    $title = pathinfo($value['name'], PATHINFO_FILENAME);
                    $file = new MyFiles();
                    $file->title = $title;
                    $file->owner_id = Auth::user()->id;
                    $file->type = 'Article';
                    $file->status = 'Draft';
                    $file->contents = $value['content'];
                    $file->save();

                    $input['id'] = $file->id;
                    $input['encrypt_id'] = Helper::encryptDecryptId($file->id);
                    self::updateEncrypt($input);

                }
            }
            DB::commit();
            return $file;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }}

    public static function checkIfSpaceContainsContent($id) {
        try {
            DB::beginTransaction();
            $file = MyFiles::where('space_id', $id)->first();
            if (isset($file) && !empty($file)) {
                $response['message'] = 'exists';
                $response['statusCode'] = '500';
            }
            else {
                $response['message'] = 'notexist';
                $response['statusCode'] = '500';
            }
            DB::commit();
            return $response;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }



    public static function getTenantFiles($tenantId,$subFolder)
    {
        $files = Storage::disk('s3')->files("tenants/{$tenantId}/{$subFolder}");
        return $files;
    }

    public static function saveFileInDatabase($id,$fileName,$filePath) {
        try {
            $post = PostAttachment::create([
                'file_id' => $id,
                'type' => 'image',
                'file_name' => $fileName,
                'file_path' => $filePath,
            ]);
        } catch (\Throwable $th) {
            dd($th->getMessage());
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public static function getSingleFeedData($id) {
        try {
            $loggedInUserId = Auth::user()->id;
            DB::beginTransaction();

            $user = User::find($loggedInUserId);

            // Retrieve the feed with the required relationships
            $feed = MyFiles::with(['user', 'likes', 'comments'])
                           ->where('id', $id)
                           ->orderBy('created_at', 'DESC')
                           ->firstOrFail();

            // Additional data processing
            $feed->likes_count = $feed->likes->count();
            $feed->comments_count = $feed->comments->count();

            // Map comments to include user info
            $feed->comments = $feed->comments->map(function($comment) {
                return [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'user' => $comment->user
                ];
            });

            // Map likes to include user info
            $feed->liked_by_users = $feed->likes->map(function($like) {
                return $like->user;
            });

            // Check if the logged-in user has liked the feed
            $feed->isLiked = $feed->likes->where('user_id', $loggedInUserId)->isNotEmpty();

            DB::commit();

            // Return the feed as JSON response
            return response()->json($feed, 200);
        } catch (\Throwable $th) {
            // Handle exception
            DB::rollBack();
            return response()->json([
                'message' => $th->getMessage(),
                'statusCode' => 500
            ], 500);
        }
    }

    public static function updateArticleStatus($file_id,$postArr) {
        try {
            DB::beginTransaction();
            $article = MyFiles::where('encrypt_id', $file_id)->first();
            if ($article) {
                $article->status = 'pending_approval';
                $article->status_comments = NULL;
                $article->title = $postArr['title'];
                $article->space_id = $postArr['space_id'];
                $article->is_shared_externally = ($postArr['is_shared_externally'] === true) ? 0 : 1;
                $article->edit_scope = $postArr['edit_scope'];
                $article->save();
            }
                DB::commit();
                return true;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }


    public static function dragedFilesIntoFolders($fileid, $folderId) {
        try {
            $files = self::find($fileid);
            // dd(  $id);
            if (!$files) {
                throw new \Exception('Folder not found.');
            }

            $files->folder_id = $folderId;
            $files->save();

            return true;
        } catch (\Throwable $th) {
            return [
                'message' => $th->getMessage(),
                'statusCode' => 500,
                'status' => false
            ];
        }
    }

    public static  function moderationsList($searchTerm,$searchSpace,$searchType){
        try {
            DB::beginTransaction();


            $listing = self::with(['user', 'space', 'moderation.reviewGroup.ReviewGroupUser.user' => function($query) {
                $query->select(['id', 'name']);
            },
            'moderation' => function($query) {
                $query->select(['id','file_id'])
                ->where('status', 3);
            }
            ]
            )
            ->where('status', 'pending_approval')

            ->orderBy('title', 'ASC')
            ->paginate(12);
            DB::commit();
            return $listing;
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }

    }

    public static function getprivateFilesID() {
        DB::beginTransaction();
        try {
            $userId = Auth::user()->id;

            $spaceFiles = MyFiles::select(['id', 'encrypt_id', 'space_id'])
            ->whereHas('Space', function ($query) use ($userId) {
                $query->where('space_type', 'PRIVATE')
                      ->select('space_id')
                      ->whereHas('SpaceUser', function ($query) use ($userId) {
                          $query->where('user_id', $userId);
                      });
            })
            ->whereNotNull('encrypt_id')
            ->get()->toArray();
            $filesId = [];
            if(!empty($spaceFiles)){
                $filesId = array_column($spaceFiles,'encrypt_id');
            }
            DB::commit();
            return $filesId;

        } catch (\Throwable $th) {
            return $th->getMessage();
        }
    }

    public static function ownCreatedFiles() {
        DB::beginTransaction();
        try {
            $userId = Auth::user()->id;

            $files = MyFiles::select(['id', 'encrypt_id', 'space_id'])
            ->where('owner_id',$userId)
            ->whereNotNull('encrypt_id')
            ->get()->toArray();
            $filesId = [];
            if(!empty($files)){
                $filesId = array_column($files,'encrypt_id');
            }
            DB::commit();
            return $filesId;

        } catch (\Throwable $th) {
            return $th->getMessage();
        }
    }

    public static function getPublicFilesID() {
        DB::beginTransaction();
        try {

            $loggedInUserId = auth()->id(); // Get the logged-in user's ID
            $spaceFiles = MyFiles::select('my_files.id', 'my_files.encrypt_id', 'my_files.space_id')
                ->join('spaces', 'my_files.space_id', '=', 'spaces.id')
                ->join('space_subscribers', 'spaces.id', '=', 'space_subscribers.space_id')
                ->where('my_files.status', 'Published')
                ->where('spaces.space_type', 'OPEN')
               // ->where('space_subscribers.user_id', $loggedInUserId)
                ->whereNotNull('my_files.encrypt_id')
                ->get()
                ->toArray();


            $filesId = [];
            if(!empty($spaceFiles)){
                $filesId = array_column($spaceFiles,'encrypt_id');
            }
            DB::commit();
            return $filesId;

        } catch (\Throwable $th) {
            dd($th->getMessage());
            return $th->getMessage();
        }
    }

    public static function saveFlag($id) {
        // DB::beginTransaction();

        // try {
            $article = Articles::withoutEvents(function() use ($id) {
                return Articles::where('file_id', $id)->first();
            });

            if ($article) {
                $article->updated_without_version = 0; // Ensure you are setting this to the desired value
                $article->save(); // Attempt to save the changes
            }

            // dd($article);
            // DB::commit(); // Commit the transaction only after the operation is successful
            return true; // Return the updated article
        // } catch (\Throwable $th) {
        //     dd($th->getMessage());
        //     return $th->getMessage();
        // }
    }

    public static function checkIfDraftExists() {
        $post = MyFiles::with(['post_attachments','space:id,name'])
            ->where(['status' => 'Draft', 'type' => 'Post'])
            ->orderBy('created_at', 'desc')
            ->first();

        // Check if post does not exist
        if(!$post) {
            return response()->json([
                'message' => 'No draft post found.',
            ], 404);
        }
        return $post;
    }

    public static function getLatestVersion($articleId) {

            $article = Articles::findOrFail($articleId);
            $versions = $article->versions()->orderBy('created_at', 'desc')->get();
            if ($versions->isEmpty()) {
                return response()->json([
                    'message' => 'No versions found for this article.',
                ], 404);
            }
            $latestVersion = $versions->first();
            dd($latestVersion);
            return $latestVersion;
    }

    public static function removeImagesIfExists($fileId) {
        $images = PostAttachment::where('file_id', $fileId)->where('type','image')->get();

        if (!$images->isEmpty()) {
            try {
                foreach ($images as $image) {
                    if (!empty($image->file_path)) {
                        // Check if the file exists before deleting
                        if (Storage::disk('s3')->exists($image->file_path)) {
                            Storage::disk('s3')->delete($image->file_path);
                        }
                    } else {
                        // Log the missing file path for debugging
                        Log::warning("File path is missing for PostAttachment ID: {$image->id}");
                    }

                    // Delete the database record
                    $image->delete();
                }
            } catch (\Exception $e) {
                // Log any exceptions for debugging
                Log::error('Error deleting images: ' . $e->getMessage());
            }
        }
    }

    public static function searchWithTitle($searchTerm,$searchFilesArr)
    {
        try {

            $cacheKey = "search_history_{$searchTerm}";

            // Cache the results for 10 minutes (600 seconds)
            $searchHistory = Cache::remember($cacheKey, 600, function () use ($searchTerm, $searchFilesArr) {
                return self::select(['encrypt_id', 'title','status','created_at','updated_at'])
                    ->whereIn('encrypt_id', $searchFilesArr)
                    ->where('title', 'LIKE', "%{$searchTerm}%")
                    ->orderBy('created_at', 'desc')
                    ->limit(4)
                    ->get();
            });
            return $searchHistory;
        } catch (\Throwable $th) {
            $response = [
                'message' => $th->getMessage(),
                'statusCode' => '500'
            ];
            return $response;
        }
    }

    public static function updatePost(Request $request){
        try {
            $existingDraft = MyFiles::where(['id' => $request->feed_id])->first();
            $existingDraft->title = $request->title;
            $existingDraft->description = $request->description;
            $existingDraft->owner_id = Auth::user()->id;
            $existingDraft->space_id = $request->space_id;
            $existingDraft->type = 'Post';
            $existingDraft->status = 'Published';
            $fileId = $existingDraft->id;
            $existingDraft->save();
            $tenant = tenant();
            $tenantId = $tenant->id;
            $subFolder = 'posts';
            if ($request->hasFile('image')) {
                $images = $request->file('image');
                self::removeImagesIfExists($fileId);
                if (is_array($images)) {
                    foreach ($images as $image) {
                        $result = self::uploadFileWithSubfolder($tenantId, $subFolder, $image);
                        self::saveFileInDatabase($fileId, $result['filename'], $result['filepath']);
                    }
                } else {
                    // Handle the case for a single image
                    $result = self::uploadFileWithSubfolder($tenantId, $subFolder, $images);
                    self::saveFileInDatabase($fileId, $result['filename'], $result['filepath']);
                    // dd($result);

                }
            }
            else {
                if($request->flag == "remove_images") {
                    self::removeImagesIfExists($fileId);
                }
            }
            if ($request->has('linked_articles') && is_array(json_decode($request->linked_articles))) {
                $sequence = 1;
                $linkedArticles = json_decode($request->linked_articles);
                foreach ($linkedArticles as $articleId) {
                    Log::info('Received linked article:', ['article_id' => $articleId]);
                    if (isset($articleId->value)) {
                        $url = $articleId->value;
                        if (filter_var($url, FILTER_VALIDATE_URL)) {
                            $fileName = $url;

                        PostAttachment::create([
                                'file_id' => $fileId,
                                'type' => 'url',
                                'file_name' => $fileName,
                                'sequence' => $sequence,
                            ]);
                        }
                    } elseif (isset($articleId->encrypt_id)) {
                        $fileName = $articleId->encrypt_id;
                        PostAttachment::create([
                            'file_id' => $fileId,
                            'type' => 'article',
                            'file_name' => $fileName,
                            'sequence' => $sequence,
                        ]);
                    } else {
                        Log::warning('No valid value or id in linked article');
                    }

                    $sequence++;
                }

                Log::info('Finished processing linked articles.');
            } else {
                Log::warning('No valid linked articles found in the request.');
            }
            if ($request->has('deleted_attachments')) {
                $deletedAttachments = json_decode($request->deleted_attachments);
                foreach ($deletedAttachments as $attachmentId) {
                    PostAttachment::deleteAttachment($attachmentId);
                }
            }

            return response()->json([
            'message' => 'Post created successfully',
            'status' => true,
            ], 200);
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            return $response;
        }
    }

    public static function deletePost($id) {
        try {
            DB::beginTransaction();
            $post = self::find($id);
            if($post) {
                $post->delete();
            }
            DB::commit();
            return response()->json([
                'message' => 'Post deleted successfully',
                'status' => true,
                ], 200);
        } catch (\Throwable $th) {
            $response['message'] = $th->getMessage();
            $response['statusCode'] = '500';
            $response['status'] = false;
            return $response;
        }
    }

    public static function getArticlesAccessibleToUser($ids)
    {
        try {
            DB::beginTransaction();

            // Fetch data from myFiles table where encrypt_id matches the provided $ids
            $files = DB::table('my_files')->select(['id','title','encrypt_id'])->whereIn('encrypt_id', $ids)->get();

            DB::commit();


            return $files;
        } catch (\Throwable $th) {
            DB::rollBack(); // Ensure the transaction is rolled back on exception

            return response()->json([
                'message' => $th->getMessage(),
                'status' => false,
            ], 500);
        }
    }


    public static function getSpaceArticles($spaceId) {
        try {
            DB::beginTransaction();
            $articles = self::select('encrypt_id')
            ->where('edit_scope', 2)
            ->where('space_id',$spaceId)
            ->pluck('encrypt_id');
            DB::commit();
            return $articles;
        }
        catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to publish the latest version and draft others.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}
