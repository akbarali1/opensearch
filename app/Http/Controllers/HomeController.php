<?php

namespace App\Http\Controllers;

use Algolia\AlgoliaSearch\SearchClient;
use App\Models\ProductModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenSearch\ClientBuilder;

/**
 * Created by PhpStorm.
 * Filename: HomeController.php
 * Project Name: opensearch
 * Author: Акбарали
 * Date: 21/12/2022
 * Time: 7:46 PM
 * Github: https://github.com/akbarali1
 * Telegram: @akbar_aka
 * E-mail: me@akbarali.uz
 */
class HomeController extends Controller
{
    protected ClientBuilder $openSearchClient;

    public function __construct(ClientBuilder $openSearchClient)
    {
        $this->openSearchClient = $openSearchClient;
        //        $this->openSearchClient;

    }

    /**
     * @throws GuzzleException
     */
    private function request()
    {
        //Guzzle HTTP Client Request
        $request = new Client(['verify' => false]);
        try {
            $response = $request->request('GET', 'https://localhost:9200');
        } catch (GuzzleException $e) {
            dd($e->getMessage());
        }
        $response = $response->getBody()->getContents();
        dd($response);
    }

    public function index()
    {
        //        $this->request();
        //http://localhost:5601
        $client    = $this->openSearchClient
            ->setHosts(['https://localhost:9201'])
            ->setBasicAuthentication('admin', 'admin')
            ->setSSLVerification(false)
            ->build();
        $indexName = 'products';

        /*
                // Create an index with non-default settings.
                try {
                    $client->indices()->create([
                        'index' => $indexName,
                        'body'  => [
                            'settings' => [
                                'index' => [
                                    'number_of_shards' => 4,
                                ],
                            ],
                        ],
                    ]);
                } catch (\Exception $exception) {
                    dump($exception->getMessage());
                }
                dd($client);*/

        $cr = $client->create([
            'index' => $indexName,
            'id'    => time(),
            'body'  => [
                'title'    => 'Moneyball',
                'director' => 'Bennett Miller',
                'year'     => 2011,
            ],
        ]);
        dd($cr);

        //        dd(
        //            $client->delete([
        //                'index' => $indexName,
        //                'id'    => 1,
        //            ])
        //        );

        $search = $client->search([
            'index' => $indexName,
            'body'  => [
                'size'  => 5,
                'query' => [
                    'multi_match' => [
                        'query'  => 'miller',
                        'fields' => ['title^2', 'director'],
                    ],
                ],
            ],
        ]);
        dd($search);

        dd($client->indices()->exists(['index' => $indexName]));

        dd($client->info());

        return view('welcome');
    }

    public function products()
    {
        $client    = $this->openSearchClient->setHosts(['https://localhost:9201'])
            ->setBasicAuthentication('admin', 'admin')
            ->setSSLVerification(false)
            ->build();
        $indexName = 'products';
        $products  = ProductModel::query()->where('status', ProductModel::STATUS_ACTIVE)
            ->select(
                'id',
                'name',
                'slug',
            )
            ->limit(5)
            ->get()->toArray();
        // dd($products);
        $arr = [];
        foreach ($products as $product) {
            $name  = [
                'name_ru' => $product['name']['ru'],
                'name_uz' => $product['name']['uz'],
            ];
            $body  = [
                'id'   => $product['id'],
                'slug' => $product['slug'],
            ];
            $merge = array_merge($body, $name);
            $arr[] = $client->index([
                'index' => $indexName,
                'id'    => $product['id'],
                'body'  => $merge,
            ]);
        }
        dd($arr);

    }

    public function search()
    {
        if (!request()->has('q')) {
            return response()->json([
                'status'  => false,
                'message' => 'Query is required',
            ]);
        }
        $text   = request()->get('q');
        $client = $this->openSearchClient->setHosts(['https://localhost:9201'])
            ->setBasicAuthentication('admin', 'admin')
            ->setSSLVerification(false)
            ->build();
        $search = $client->search([
            'index' => 'products',
            'body'  => [
                'size'  => 16,
                'query' => [
                    'multi_match' => [
                        'query'  => $text,
                        'fields' => [
                            'name_ru',
                            'name_uz',
                        ],
                    ],
                ],
            ],
        ]);
        foreach ($search['hits']['hits'] as $hit) {
            $data[] = $hit['_source'];
        }

        return response()->json($data ?? []);
    }

    public function prefix()
    {
        if (!request()->has('q')) {
            return response()->json([
                'status'  => false,
                'message' => 'Query is required',
            ]);
        }
        $text = request()->get('q');
        try {
            $client = $this->openSearchClient->setHosts(['https://localhost:9201'])
                ->setBasicAuthentication('admin', 'admin')
                ->setSSLVerification(false)
                ->build();
            $search = $client->search($this->formatSearch($text));
        } catch (\Exception $exception) {
            return response()->json(json_decode($exception->getMessage(), true));
        }
        foreach ($search['hits']['hits'] as $hit) {
            $hit['_source']['_score']    = $hit['_score'];
            $hit['_source']['highlight'] = $hit['highlight'];
            $data[]                      = $hit['_source'];
        }

        return response()->json(
            [
                'text'  => $text,
                'total' => $search['hits']['total']['value'],
                'data'  => $data ?? [],
                // 'search' => $search,
            ]
        );
    }

    public function algolia()
    {
        if (!request()->has('q')) {
            return response()->json([
                'status'  => false,
                'message' => 'Query is required',
            ]);
        }
        $text = request()->get('q');

        $client  = SearchClient::create("A5B4K31EU0", "eda4859f22aa9dece7d7a0ca766b0dc0");
        $index   = $client->initIndex("products");
        $results = $index->search($text);
        dd($results);

        return response()->json($results ?? []);
    }

    //Text check cyrillic to latin and latin to cyrillic
    public function transliterate($text)
    {
        $cyr = [
            'А',
            'Б',
            'В',
            'Г',
            'Д',
            'Е',
            'Ё',
            'Ж',
            'З',
            'И',
            'Й',
            'К',
            'Л',
            'М',
            'Н',
            'О',
            'П',
            'Р',
            'С',
            'Т',
            'У',
            'Ф',
            'Х',
            'Ц',
            'Ч',
            'Ш',
            'Щ',
            'Ъ',
            'Ы',
            'Ь',
            'Э',
            'Ю',
            'Я',
            'а',
            'б',
            'в',
            'г',
            'д',
            'е',
            'ё',
            'ж',
            'з',
            'и',
            'й',
            'к',
            'л',
            'м',
            'н',
            'о',
            'п',
            'р',
            'с',
            'т',
            'у',
            'ф',
            'х',
            'ц',
            'ч',
            'ш',
            'щ',
            'ъ',
            'ы',
            'ь',
            'э',
            'ю',
            'я',
        ];
        $lat = [
            'A',
            'B',
            'V',
            'G',
            'D',
            'E',
            'E',
            'J',
            'Z',
            'I',
            'Y',
            'K',
            'L',
            'M',
            'N',
            'O',
            'P',
            'R',
            'S',
            'T',
            'U',
            'F',
            'H',
            'C',
            'CH',
            'SH',
            'SH',
            '',
            'Y',
            '',
            'E',
            'YU',
            'YA',
            'a',
            'b',
            'v',
            'g',
            'd',
            'e',
            'e',
            'j',
            'z',
            'i',
            'y',
            'k',
            'l',
            'm',
            'n',
            'o',
            'p',
            'r',
            's',
            't',
            'u',
            'f',
            'h',
            'c',
            'ch',
            'sh',
            'sh',
            '',
            'y',
            '',
            'e',
            'yu',
            'ya',
        ];
        //text check cyrillic
        if (preg_match('/[А-Яа-яЁё]/u', $text)) {
            return str_replace($cyr, $lat, $text);
        }
        //text check latin
        if (preg_match('/[A-Za-z]/u', $text)) {
            return str_replace($lat, $cyr, $text);
        }
    }

    private function formatSearch($text): array
    {
        return [
            //note 11s 6/64 star
            'index' => 'products',
            'body'  => [
                'from'      => 0,
                'size'      => 10000,
                'highlight' => [
                    //"order"  => "score",
                    'fields' => [
                        'name_ru'    => [
                            'pre_tags'  => ['<b>'],
                            'post_tags' => ['</b>'],
                        ],
                        'name_uz'    => [
                            'pre_tags'  => ['<b>'],
                            'post_tags' => ['</b>'],
                        ],
                        'text_entry' => [
                            'type' => 'plain',
                        ],
                    ],
                ],
                'query'     => [
                    /*'multi_match' => [
                        'fields'               => [
                            'name_ru',
                            'name_uz',
                        ],
                        'query'                => $text.'*'.' | '.$this->transliterate($text).'*',
                        'fuzziness'            => 'AUTO',
                        'fuzzy_transpositions' => true,
                        'minimum_should_match' => 1,
                        'analyzer'             => 'standard',
                        'boost'                => 1,
                        'prefix_length'        => 3,
                        'max_expansions'       => 50,
                        //'operator'             => 'or',
                    ],*/
                    'simple_query_string' => [
                        'query'                               => $text.'*'.' | '.$this->transliterate($text).'*',
                        'fields'                              => [
                            'name_uz',
                            'name_ru',
                        ],
                        'flags'                               => 'ALL',
                        'fuzzy_transpositions'                => true,
                        'fuzzy_max_expansions'                => 50,
                        'fuzzy_prefix_length'                 => 0,
                        'minimum_should_match'                => 1,
                        'default_operator'                    => 'OR',
                        'analyzer'                            => 'standard',
                        'lenient'                             => false,
                        'quote_field_suffix'                  => '',
                        'analyze_wildcard'                    => true,
                        'auto_generate_synonyms_phrase_query' => true,
                        'boost'                               => 1,
                    ],
                ],
            ],
        ];
    }
}
