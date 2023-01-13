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
        $text   = request()->get('q');
        $client = $this->openSearchClient->setHosts(['https://localhost:9201'])
            ->setBasicAuthentication('admin', 'admin')
            ->setSSLVerification(false)
            ->build();
        $search = $client->search([
            //note 11s 6/64 star
            'index' => 'products',
            'body'  => [
                'query' => [
                    'simple_query_string' => [
                        'query'                               => $text.'*',
                        'fields'                              => [
                            'name_ru',
                            'name_uz',
                        ],
                        'flags'                               => 'ALL',
                        'fuzzy_transpositions'                => true,
                        'fuzzy_max_expansions'                => 50,
                        'fuzzy_prefix_length'                 => 0,
                        'minimum_should_match'                => 1,
                        'default_operator'                    => 'or',
                        'auto_generate_synonyms_phrase_query' => true,
                    ],
                ],
            ],
        ]);
        foreach ($search['hits']['hits'] as $hit) {
            $data[] = $hit['_source'];
        }

        return response()->json(
            [
                'text' => $text,
                'data' => $data ?? [],
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
}
