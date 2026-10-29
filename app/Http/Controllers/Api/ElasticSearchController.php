<?php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\ArticlesShared;
use App\Models\MyFiles;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Models\SearchHistory;
use App\Models\searchPrompt;
use App\Models\RecentViewedArticles;
use Illuminate\Support\Facades\Log;
use App\Models\meetingPrompt;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use App\Helpers\Helper;

class ElasticSearchController extends Controller
{
    protected $elasticsearch;

    public function __construct(Client $elasticsearch)
    {
        $this->elasticsearch = $elasticsearch;
    }

    public function searchGlobal(Request $request)
    {
        try {
            $tenant = tenant();
            $tenantId = $tenant->id;
            $userId = Auth::user()->id;
            //$searchTerm = $request->query('search');
            $input = $request->all();
            $searchTerm = $input['searchitem'];
            $create_article = '';
            if(isset($input['create_article']) && $input['create_article'] == 'yes'){


            }

            $getSearchFileIN = self::searchableFilesId();
            $searchFilesArr = array_filter($getSearchFileIN);
            $cacheKey = "search_{$tenantId}_{$userId}_{$searchTerm}";
            // Check if results are cached
            $cachedResults = Cache::get($cacheKey);
            if ($cachedResults) {
                return response()->json([
                    'data' => $cachedResults,
                    'message' => 'Results retrieved from cache',
                    'status' => true,
                 ], 200);
              }

            $searchTerm = strtolower($searchTerm);

            // get domain name
            $getDomain = Helper::getDomain();
            $indexName = $getDomain.'_tenants_' . $tenantId;

            $must = [];
            if (isset($input['create_article']) && $input['create_article'] == 'yes') {
                $create_article = $input['create_article'];
                $must[] = [
                    'term' => [
                        'type' => 'Articles'
                    ]
                ];
            }
            $must[] = [
                'terms' => [
                    'file_encrypt_id' => array_values($searchFilesArr), // Must include selected files
                ],
            ];

            $params = [
                'index' => $indexName,
                '_source' => ['file_encrypt_id', 'type', 'title', 'user_id', 'content', 'link'],
                'body' => [
                    'size' => 3,
                    'query' => [
                        'bool' => [
                            'must' => $must,
                            'should' => [
                                [
                                    'match_phrase' => [
                                        'content' => [
                                            'query' => $searchTerm,
                                            'boost' => 15.0, // Boost the relevance if needed
                                        ],
                                    ],
                                ],
                                [
                                    'sparse_vector' => [
                                        'field' => 'content_embedding',
                                        'inference_id' => '.elser_model_2_linux-x86_64',
                                        'query' => $searchTerm,
                                        'boost' => 12.0,
                                    ],
                                ],
                            ],
                            'minimum_should_match' => 1, // Adjust this if you have should clauses
                        ],
                    ],
                    'min_score' => 2, // Adjust as needed for relevance
                ],
            ];


                // Execute the search query
                $response = $this->elasticsearch->search($params);

                if($create_article == 'yes'){
                    $results = $this->processSearchResultsToNewArticle($response,$searchTerm);
                    if(empty($results)){
                        return response()->json([
                            'message' => 'No relevant content found to create an article',
                            'status' => false,
                            'article_id' => 0,
                        ], 200);
                    }
                    $data = [
                        'content' => $results,
                        'title' => $searchTerm,
                        'user_id' => Auth::user()->id,
                        'type' => 'article',
                    ];
                    $createdArticle = MyFiles::createOpenAIArticle($data);
                    if($createdArticle){
                        return response()->json([
                            'message' => 'data retrieved successfully',
                            'status' => true,
                            'article_id' => $createdArticle,
                        ], 200);
                    }
                }
                $results = $this->processSearchResults($response,$searchTerm);
                Cache::put($cacheKey, $results, now()->addMinutes(10));
                //$gethistory = $this->searchKeywordHistory($searchTerm);

                return response()->json([
                    'message' => 'data retrieved successfully',
                    'status' => true,
                    'search' => $results,
                    //'history' => $gethistory
                ], 200);

            } catch (\Exception $e) {
                Log::error('Elasticsearch search error: ' . $e->getMessage());
                return response()->json(['error' => 'Search failed'], 500);
            }
        }

        // Helper function to calculate percentile
        private function calculatePercentile($scores, $percentile)
        {
            sort($scores);
            $index = ($percentile * (count($scores) - 1));
            $lower = floor($index);
            $upper = ceil($index);
            $weight = $index - $lower;

            if ($lower == $upper) return $scores[$lower];
            return $scores[$lower] * (1 - $weight) + $scores[$upper] * $weight;
        }
        private function processSearchResults($response, $searchTerm)
        {
            // Extract and format the search results
            $count = 1;
            $results = [];
            $scoreArr = [];
            $documentContent = '';
            $documentSource = [];
            $prompts = self::getSearchPrompt();
            if(empty($response['hits']['hits'])){
                return $results;
            }
            foreach ($response['hits']['hits'] as $key => $hit) {
                $score = $hit['_score'];
                $key++;

                // Extract all scores
                $scores = array_column($response['hits']['hits'], '_score');
                $maxScore = max($scores);
                $relativeThreshold = $maxScore * 0.5; // 50% of the max score

                // Only include scores above the higher of 75th percentile or relative threshold
                if ($score >= $relativeThreshold) {
                    $scoreArr[] = $score;
                    $sectionContent = isset($hit['_source']['content']) ? 'Section ' . ($count) . ' : ' . $hit['_source']['content'] . "\n" : '';
                    $documentContent .= $sectionContent;
                    $documentSource[] = $hit['_source'];
                    $count++;
                }
            }
            $userPrompt = str_replace('[search-term]', '`'.$searchTerm. '`', $prompts[1]['content']);
            $userPrompt = str_replace('[documentContent]', $documentContent, $userPrompt);

            $messages = [
                [
                    'role' => 'system',
                    'content' => trim($prompts[0]['content'] ?? '')
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt
                ]
            ];

            $maxTokens = (int) min(200, max(20, strlen(json_encode($messages)) / 10));
            $response = OpenAI::chat()->create([
                'model' => "ft:gpt-4o-2024-08-06:knowbee::AG3A6OGw",
                //'model' => "gpt-3.5-turbo",
                //'model' => "ft:gpt-3.5-turbo-0125:knowbee::AEXywjoq",
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => 0
            ]);


            // Ensure response contains choices
            if (!empty($response['choices'])) {
                $messageOpenAI = trim($response['choices'][0]['message']['content']);
                $messageOpenAI = str_replace('#','',$messageOpenAI);
                $messageOpenAI = str_replace('-','&nbsp; • ',$messageOpenAI);
                $messageOpenAI = preg_replace('/\*\*(.*?)\*\*/', '<b>$1</b>', $messageOpenAI);
                if($messageOpenAI == 'nothing'  || $messageOpenAI == 'Nothing' || $messageOpenAI == '- nothing' || $messageOpenAI == 'nothing###' || $messageOpenAI == 'Nothing.' || $messageOpenAI == '`nothing`' || $messageOpenAI == 'nothing.' ){
                    $messageOpenAI = '';
                }else{
                    $results[] = [
                        'score' => $scoreArr,
                        'source' => $documentSource,
                        'highlights' =>  $messageOpenAI, // Retrieve highlighted matches
                    ];

                }
            } else {
                Log::warning('No choices found in OpenAI response for document ID: ' . $hit['_id']);
            }

            return $results;
        }

        private function processSearchResultsToNewArticle($response,$searchTerm)
        {
            // Extract and format the search results
            $results = [];
            $count = 1;
            $documentContent = '';


            if(empty($response['hits']['hits'])){
                return $results;
            }
            foreach ($response['hits']['hits'] as $key => $hit) {
                    $sectionContent = isset($hit['_source']['content']) ?  'Notes Section ' . ($count) . ' : ' . $hit['_source']['content'] . "\n" : '';
                    $documentContent .= $sectionContent;
                    $count++;
                }

            $meetingPrompt = meetingPrompt::first();
            $getprompt = $meetingPrompt->prompt;

            // Replace placeholders without backticks
            $getprompt = str_replace('[prompt]', $searchTerm, $getprompt);
            $getprompt = str_replace('[meeting-notes]', $documentContent, $getprompt);

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4.1-2025-04-14',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $getprompt,
                    ],
                ],
                'max_tokens' => 2000,
                'temperature' => 0.7,
                'n' => 1
            ]);



            // Ensure response contains choices
            if (!empty($response['choices'])) {
                $messageOpenAI = trim($response['choices'][0]['message']['content']);
                $messageOpenAI = preg_replace('/\*\*(.*?)\*\*/', '<b>$1</b>', $messageOpenAI);
                $messageOpenAI = str_replace('- ', '&nbsp; • ', $messageOpenAI);
                $messageOpenAI = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $messageOpenAI);
                $messageOpenAI = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $messageOpenAI);
                $messageOpenAI = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $messageOpenAI);
                $messageOpenAI = nl2br($messageOpenAI);

                if (in_array(strtolower(trim(strip_tags($messageOpenAI))), ['nothing', 'nothing.', '`nothing`'])) {
                    return '';
                } else {
                    return $messageOpenAI;
                }
            }


            return $results;
        }


    public function searchWithAI(Request $request)
    {
        // Get the user input
        $tenant = tenant();
        $tenantId = $tenant->id;
        $userId = Auth::user()->id;
        $searchTerm = $request->query('search');
        $cacheKey = "ai_search_{$tenantId}_{$userId}_{$searchTerm}";

        // Prepare the prompt for OpenAI
        $prompt = "Based on the query '{$searchTerm}', please provide relevant information or insights.";

        // Call OpenAI API to get the generative response
        $aiResponse = $this->getAiGenerativeResults($prompt);

        return response()->json([
            'data' => $aiResponse,
            'status' => true,
        ], 200);
    }


    public function searchableFilesId()
    {
        // get public and private space files

        $private = MyFiles::getprivateFilesID() ?? [];;
        $public = MyFiles::getPublicFilesID() ?? [];
        $shared = ArticlesShared::getUserSharedArticle() ?? [];
        $ownFiles = MyFiles::ownCreatedFiles() ?? [];

        $mergedArray = array_merge($private, $public, $shared,$ownFiles);

        return $mergedArray;
    }

    public function getSearchPrompt(){
        try {
            $promts = searchPrompt::getPrompts();
            return $promts;
        } catch (\Throwable $th) {
            dd($th->getMessage());
        }

    }

    public function getAiGenerativeResults($prompt)
    {

        $response = OpenAI::chat()->create([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens' => 100, // Adjust based on your needs
            'n' => 1
        ]);

        // Extract and return the AI response
        return $response['choices'][0]['message']['content'] ?? 'No response generated.';
    }



    public function searchSave(Request $request)
    {
        try {
            $searchTerm = $request->query('search');
            SearchHistory::addHistory($searchTerm);
            return response()->json(['status' => 'success', 'message' => 'Search term saved successfully'], 200);
        } catch (\Throwable $th) {
            return response()->json(['status' => 'error', 'message' => 'Failed to save search term'], 500);
        }
    }

    public function searchKeywordHistory(Request $request)
    {
        try {
            $searchTerm = $request->query('search');
            $history = SearchHistory::fetchHistorykeyword($searchTerm);
            $articlesHistory = RecentViewedArticles::fetchData();
            return response()->json([
                'data' => $history,
                'files_history' => $articlesHistory,
                'message' => 'History listed successfully',
                'status' => true,
            ], 200);
        } catch (\Throwable $th) {

            return response()->json([
                'message' => $th->getMessage()
            ], 500);
        }

    }

    public function searchHistory(Request $request)
    {
        try {
            $searchTerm = $request->query('search');
            $history = SearchHistory::fetchHistory($searchTerm);
            return response()->json([
                'data' => $history,
                'message' => 'History listed successfully',
                'status' => true,
            ], 200);
        } catch (\Throwable $th) {

            return response()->json([
                'message' => $th->getMessage()
            ], 500);
        }

    }

    public function searchWithTitle(Request $request)
    {
        try {
            $input = $request->all();
            $searchTerm = $input['searchitem'];
            $getSearchFileIN = self::searchableFilesId();
            $searchFilesArr = array_filter($getSearchFileIN);
            $files = MyFiles::searchWithTitle($searchTerm,$searchFilesArr);
            return response()->json([
                'data' => $files,
                'message' => 'History listed successfully',
                'status' => true,
            ], 200);
        } catch (\Throwable $th) {

            return response()->json([
                'message' => $th->getMessage()
            ], 500);
        }

    }

    public function deleteSearchedHistory($id)
    {
        try {
            // Attempt to delete the article
            $result = SearchHistory::deleteSearchHistory($id);
            if ($result) {
                return response()->json([
                    'data' => '',
                    'message' => 'Deleted search history successfully',
                    'status' => true,
                ], 200);
            }
        } catch (\Throwable $th) {
            // Return error response
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }



    public function autoCompleteSentence(Request $request)
    {
        try {
            $searchTerm = $request->query('search');
            $prompt = "Provide five unique and natural sentence completions for: '{$searchTerm}'.
            Return the response as a PHP array.";

            $response = OpenAI::chat()->create([
                'model' => "gpt-3.5-turbo",
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'max_tokens' => 20,  // Reduce to avoid long responses
                'temperature' => 0.5,  // Slight randomness for varied responses
                'n' => 1,
                'stop' => ["\n"],  // Ensures it doesn't continue too much
            ]);

            // Convert the content to an array
            $content = $response['choices'][0]['message']['content'];
            $contentlimitedArray = [];
            if($content){

                $content = str_replace(['[', ']','\\','"',"'", '###'], '', $content);
                $content  = explode(',', $content);
                $content = array_unique(array_filter($content));
                $contentlimitedArray = array_slice($content, 0, 5);
            }
            return response()->json([
                'autocomplete' => $contentlimitedArray,
                'message' => 'History listed successfully',
                'status' => true,
            ], 200);
        } catch (\Throwable $th) {

            return response()->json([
                'message' => $th->getMessage()
            ], 500);
        }

    }


}
