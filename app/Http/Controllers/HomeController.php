<?php

namespace App\Http\Controllers;

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
        $client = $this->openSearchClient
            ->setHosts(['https://localhost:9200'])
            ->setBasicAuthentication('admin', 'admin')
            ->setSSLVerification(false)
            ->build();

        $indexName = 'products';

        // Create an index with non-default settings.
        /*try {
            $client->indices()->create([
                'index' => $indexName,
                'body' => [
                    'settings' => [
                        'index' => [
                            'number_of_shards' => 4
                        ]
                    ]
                ]
            ]);
        }catch (\Exception $exception){
            dump($exception->getMessage());
        }*/
        //        dd($client);

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
        $client    = $this->openSearchClient->setHosts(['https://localhost:9200'])
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
        $arr       = [];
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
        $text   = request()->get('search');
        $client = $this->openSearchClient->setHosts(['https://localhost:9200'])
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
        dd($search['hits']['hits']);
    }
}
