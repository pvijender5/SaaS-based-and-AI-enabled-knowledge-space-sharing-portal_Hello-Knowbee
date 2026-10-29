<?php
namespace App\Observers;

use App\Models\Articles;
use App\Models\User;
use App\Models\MyFiles;
use App\Models\ModerationComment;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Elastic\Elasticsearch\ClientBuilder;
use App\Helpers\Helper;

class ArticlesObserver
{
    protected $elasticsearch;

    public function __construct()
    {
        $this->elasticsearch = app(Client::class);
    }

    public function created(Articles $article)
    {
        DB::afterCommit(function () use ($article) {
            ModerationComment::updateModerationCommentData($article);
            //$this->syncToElasticsearch($article);
        });
    }

    public function updated(Articles $article)
    {
        DB::afterCommit(function () use ($article) {
                ModerationComment::updateModerationCommentData($article);
                //$this->syncToElasticsearch($article);

        });
    }

    public function deleted(Articles $article){

        DB::afterCommit(function () use ($article) {
            $this->deleteFromElasticsearch($article);
        });
    }

    public function deleteFromElasticsearch(Articles $article){
        try {
            $tenant = tenant();
            $getDomain = Helper::getDomain();
            $indexName = $getDomain.'_tenants_' . $tenant->id;
            $params = [
                'index' => $indexName,
                'id' => $article->file_id,
            ];

            $this->elasticsearch->delete($params);
        } catch (\Exception $e) {
            Log::error('Elasticsearch delete error for article ID ' . $article->id . ': ' . $e->getMessage());
        }
    }


    public function syncToElasticsearch($article){
        try {
            // Convert array to object if needed
            if (is_array($article)) {
                $article = (object) $article;
            }

            $owner_id = User::getTenantNameById($article->owner_id ?? $article->user_id);
            $spacename = MyFiles::getSpaceName($article->file_id ?? $article->meeting_id);
            $tenant = tenant();

            // Choose between pipeline embedding or OpenAI embedding
            $usePipelineEmbedding = true; // Set this based on your preference

            if(isset($article->type) && $article->type == 'Meeting'){
                $fields = [
                    'article_id' => $article->meeting_id,
                    'file_encrypt_id' => $article->meeting_id,
                    'user_id' => $article->user_id,
                    'space_name' => '',
                    'created_at' => $article->created_at,
                    'owner_name' => '',
                    'content' => $this->processContent($article->content),
                    'title' => $article->title,
                    'type' => 'Meeting',
                    'status' => 1,
                ];
            }else{
                // Process content - encrypt only if not already encrypted
                $content = $article->contents;
                $content = Helper::encryptDecryptId($content, 'd');

                $fields = [
                    'article_id' => $article->id,
                    'file_encrypt_id' => $article->file_id,
                    'user_id' => $article->user_id,
                    'space_name' => $spacename->space->name,
                    'created_at' => $article->created_at,
                    'owner_name' => $owner_id['data'],
                    'content' => $this->processContent($article->title.' - '.$content),
                    'title' => $this->processTitle($article->title),
                    'type' => 'Articles',
                    'status' => $article->status,
                ];
            }

            // Generate embedding if not using pipeline
            if (!$usePipelineEmbedding) {
                $embedding = $this->generateEmbedding($article->contents);
                $fields['embedding'] = $embedding; // Add embedding field dynamically
            }

            // get domain name
            $getDomain = Helper::getDomain();
            $indexName = $getDomain.'_tenants_' . $tenant->id;

            if (!$this->indexExists($indexName)) {
                $this->createIndex($indexName);
                sleep(1);
            }

            // dd($fields);

            if(isset($article->type) && $article->type == 'Meeting'){
                $params = [
                    'index' => $indexName,
                    'id' => $article->meeting_id,
                    'pipeline' => 'elser-circle',
                    'routing' => $tenant->id,
                    'body' => $fields,
                ];
                $log = $this->elasticsearch->index($params);
                Log::info('Elasticsearch index response: ' . json_encode($log));
            }else{
                if (!$article->trashed()) {
                    $params = [
                        'index' => $indexName,
                        'id' => $article->meeting_id ?? $article->file_id,
                        'pipeline' => 'elser-circle',
                        'routing' => $tenant->id,
                        'body' => $fields, // Use the dynamic fields here
                    ];
                        $log = $this->elasticsearch->index($params);
                        Log::info('Elasticsearch index response: ' . json_encode($log));
                } else {
                    // Delete the document if it is trashed
                    $params = [
                        'index' => $indexName,
                        'id' => $article->meeting_id ?? $article->file_id,
                    ];
                    $this->elasticsearch->delete($params);
                }
            }

        } catch (\Exception $e) {
            Log::error('Elasticsearch sync error for article ID ' . ($article->meeting_id ?? $article->id) . ': ' . $e->getMessage());
            throw $e;
        }
    }

    private function indexExists($indexName)
    {
        $params = ['index' => $indexName];
        return $this->elasticsearch->indices()->exists($params)->asBool();
    }

    private function createIndex($indexName)
    {
    $params = [
        'index' => $indexName,
        'body' => [
            'mappings' => [
                'properties' => [
                    'article_id' => [
                        'type' => 'integer',
                    ],
                    'file_encrypt_id' => [
                        'type' => 'keyword',
                    ],
                    'user_id' => [
                        'type' => 'integer',
                    ],
                    'space_name' => [
                        'type' => 'text',
                    ],
                    'created_at' => [
                        'type' => 'date',
                        'format' => "yyyy-MM-dd'T'HH:mm:ss.SSSSSS'Z'",
                    ],
                    'owner_name' => [
                        'type' => 'text',
                    ],
                    'content' => [
                        'type' => 'text',
                    ],
                    'title' => [
                        'type' => 'text',
                    ],
                    'link' => [
                        'type' => 'text',
                    ],
                    'type' => [
                        'type' => 'keyword',
                    ],
                    'mimeType' => [
                        'type' => 'keyword',
                    ],
                    'status' => [
                        'type' => 'keyword',
                    ],
                    'content_embedding' => [
                        'type' => 'sparse_vector',
                    ],
                ],
            ],
        ],
    ];

    $this->elasticsearch->indices()->create($params);
}

    private function generateEmbedding($text)
    {
        $response = $this->getEmbeddingFromModel($text);

        if (isset($response['data'][0]['embedding'])) {
            return $response['data'][0]['embedding'];
        } else {
            return [];
        }
    }

    private function getEmbeddingFromModel($text)
    {
        $apiKey = env('OPENAI_API_KEY');
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ])->post('https://api.openai.com/v1/embeddings', [
            'input' => $text,
            'model' => 'text-embedding-ada-002' // Specify your model
        ]);

        if ($response->successful()) {
            return $response->json();
        } else {
            Log::error('Embedding generation error: ' . $response->body());
            return [];
        }
    }


    private function processTitle($title)
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\s]/', '', $title);
        return $title;
    }

    function processContent($input) {
        // Replace specific tags with newlines
        $input = str_replace(['<p>', '</p>', '<br>', '<br/>', '<br />'], "\n", $input);
        $input = str_replace(['<li>', '</li>'], ' ', $input);
        $input = str_replace(['<ol>', '</ol>', '<ul>', '</ul>'], '', $input);

        // Remove any remaining HTML tags
        $textWithoutTags = strip_tags($input);

        // Replace multiple spaces and newlines with a single space
        $cleanedText = preg_replace('/[\s]+/', ' ', $textWithoutTags);

        // Trim leading or trailing spaces
        return trim($cleanedText);
    }


}

