<?php

namespace App\Http\Services;

use App\DataObjects\DataObjectCollection;
use App\Filters\Web\Product\PriceFilter;
use App\Filters\Web\Product\SortFilter;
use App\Models\ProductModel;
use App\Services\AdminService;
use App\Services\ProductService;
use App\Services\TelegramService;
use App\ViewModels\Web\Product\ProductSearchViewModel;
use JetBrains\PhpStorm\NoReturn;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;
use Illuminate\Routing\Pipeline;

/**
 * Created by PhpStorm.
 * Filename: OpenSearchService.php
 * Project Name: opensearch
 * Author: akbarali
 * Date: 16/01/2023
 * Time: 20:09
 * Github: https://github.com/akbarali1
 * Telegram: @akbar_aka
 * E-mail: me@akbarali.uz
 */
class OpenSearchService
{
    public const INDEX_NAME            = 'products';
    public const SEARCH_LOGS           = 'search_logs';
    public const SEARCH_LOGS_NO_RESULT = 'search_logs_no_result';
    protected Client $setClient;

    public function __construct()
    {
        $this->setClient = ClientBuilder::create()->setHosts(['https://localhost:9201'])
            ->setBasicAuthentication('admin', 'admin')
            ->setSSLVerification(false)
            ->build();
    }

    private function synonymParams(): array
    {
        $synonymUrlJson = "https://raw.githubusercontent.com/akbarali1/synonyms-dictionary-uz/main/uz/object.json";
        $synonym        = file_get_contents(
            $synonymUrlJson,
            false,
            stream_context_create([
                "ssl" => [
                    "verify_peer"       => false,
                    "verify_peer_name " => false,
                ],
            ])
        );
        $synonym        = json_decode($synonym, true, 512, JSON_THROW_ON_ERROR);

        return [
            'settings' => [
                "index" => [
                    "analysis" => [
                        "filter"   => [
                            "synonym_filter" => [
                                "type"     => "synonym",
                                "synonyms" => $synonym,
                            ],
                        ],
                        "analyzer" => [
                            "synonym_analyzer" => [
                                "tokenizer" => "standard",
                                "filter"    => [
                                    "lowercase",
                                    "synonym_filter",
                                ],
                            ],
                        ],

                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'id'          => [
                        'type' => 'integer',
                    ],
                    'model'       => [
                        'type'     => 'text',
                        'analyzer' => 'synonym_analyzer',
                    ],
                    'name_uz'     => [
                        'type'     => 'text',
                        'analyzer' => 'synonym_analyzer',
                    ],
                    'name_ru'     => [
                        'type'     => 'text',
                        'analyzer' => 'synonym_analyzer',
                    ],
                    'price'       => [
                        'type' => 'integer',
                    ],
                    'mxik'        => [
                        'type' => 'text',
                    ],
                    'quantity'    => [
                        'type' => 'integer',
                    ],
                    'supplier_id' => [
                        'type' => 'integer',
                    ],
                ],
            ],
        ];
    }

    public function createIndex(string $indexName, array $body): void
    {
        try {
            $client = $this->setClient->indices()->create([
                'index' => $indexName,
                'body'  => $body,
            ]);
        } catch (\Exception $exception) {
            dd(print_r($exception->getMessage()));
        }
        dd($client);
    }

    public function createSearchData(string $index, $body, $id = false): callable|array
    {
        $params = [
            'index' => $index,
            'body'  => $body,
        ];
        if ($id) {
            $params['id'] = $id;
        }

        return $this->setClient->index($params);
    }

    public function deleteIndex(): void
    {
        $client = $this->setClient->indices()->delete([
            'index' => self::INDEX_NAME,
        ]);
        dump($client);
    }

    //Reindex
    public function reindex(): void
    {
        $this->deleteIndex();
        $this->createIndex(self::INDEX_NAME);
    }

    public function getSettings(): void
    {
        try {
            $client = $this->setClient->indices()->getSettings([
                'index' => self::INDEX_NAME,
            ]);
        } catch (\Exception $exception) {
            dd(print_r($exception->getMessage()));
        }
        dd($client);
    }

    #[NoReturn] public function addAllProducts(): void
    {
        $products = ProductModel::query()->where('status', '=', ProductModel::STATUS_ACTIVE)
            ->select(
                'id',
                'name',
                //'slug',
                'model',
                'price',
                'mxik',
                'quantity',
                'supplier_id',
            )
            ->get()->toArray();
        $arr      = [];
        foreach ($products as $product) {
            $merge = array_merge([
                'id'          => $product['id'],
                'model'       => $product['model'],
                'price'       => $product['price'],
                'mxik'        => $product['mxik'],
                'quantity'    => $product['quantity'],
                'supplier_id' => $product['supplier_id'],
            ], [
                'name_ru' => $product['name']['ru'],
                'name_uz' => $product['name']['uz'],
            ]);
            $arr[] = $this->createSearchData(self::INDEX_NAME, $merge, $product['id']);
        }
        dd($arr);
    }

    //index get all data
    public function getAllData(string $indexName): void
    {
        $params = [
            'index' => $indexName,
            'body'  => [
                'query' => [
                    'match_all' => new \stdClass(),
                ],
            ],
        ];
        $query  = $this->setClient->search($params);
        dd($query);
    }

    public function search($text, $page = 1, $limit = 15, $paginate = false)
    {
        // $this->getAllData(self::SEARCH_LOGS);
        // $this->createIndex(self::SEARCH_LOGS_NO_RESULT, ['settings' => ['index' => ['number_of_shards' => 4]]]);
        $search      = collect([]);
        $parseSearch = collect([]);

        if (strlen($text) > 3) {
            $this->createSearchData(self::SEARCH_LOGS, ['query' => $text]);
        }

        try {
            $response = $this->setClient->search($this->formatSearch($text, $limit, $paginate));
        } catch (\Exception $exception) {
            dd(print_r($exception->getMessage()));
        }

        if (count($response['hits']['hits']) === 0) {
            $this->createSearchData(self::SEARCH_LOGS_NO_RESULT, ['query' => $text]);
        }

        foreach ($response['hits']['hits'] as $key => $value) {
            $value['_source']['name']['ru'] = $value['highlight']['name_ru'][0] ?? $value['_source']['name_ru'];
            $value['_source']['name']['uz'] = $value['highlight']['name_uz'][0] ?? $value['_source']['name_uz'];
            unset($value['_source']['name_ru'], $value['_source']['name_uz']);
            $parseSearch->push($value['_source']);
            $search->push($value['_source']['id']);
        }
        $model = app(Pipeline::class)->send(
            ProductModel::query()
                ->distinct()
                ->whereIn('products.id', $search)
                ->where('products.status', '=', ProductModel::STATUS_ACTIVE)
        )->through([
        ])->thenReturn();
        if ($search->count() > 0) {
            $model->orderByRaw('FIELD(products.id, '.$search->implode(',').')');
        }
        $model->select('products.*');
        $count = $model->count();
        $skip  = $limit * ($page - 1);
        $query = $model->skip($skip)->take($limit)->get();
        $items = (new ProductService())->findImageMerge($query->pluck('id'), $query, true);
        $items = $items->transform(function ($item) use ($parseSearch) {
            $intend_price         = $item->intend_price;
            $item                 = $item->toArray();
            $item['name']         = $parseSearch->where('id', $item['id'])->first()['name'];
            $item['intend_price'] = $intend_price;

            return $item;
        });
        if ($paginate) {
            return new DataObjectCollection($items, $count, $limit, $page);
        }

        return $items->transform(function ($item) {
            return new ProductSearchViewModel($item);
        });
    }

    private function formatSearch($text): array
    {
        return [
            'index' => self::INDEX_NAME,
            'body'  => [
                'from'      => 0,
                'size'      => 10000,
                'highlight' => [
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
                        'query'                               => $text.'*'.' | '.AdminService::cyrillicToLatin($text).'*',
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
                'sort'      => [
                    '_score' => [
                        'order' => 'desc',
                    ],
                ],
            ],
        ];
    }


}
