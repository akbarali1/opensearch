<?php

namespace App\Services;

use App\Models\ProductModel;
use Illuminate\Routing\Pipeline;
use JetBrains\PhpStorm\NoReturn;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

/**
 * Created by PhpStorm.
 * Filename: ${FILE_NAME}
 * Project Name: opensearch
 * Author: akbarali
 * Date: 16/01/2023
 * Time: 20:21
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
        dump($client);
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

    public function deleteIndex(string $indexName): void
    {
        $client = $this->setClient->indices()->delete([
            'index' => $indexName,
        ]);
        dump($client);
    }

    //Reindex
    public function reindex(): void
    {
        $this->deleteIndex(self::INDEX_NAME);
        $this->createIndex(self::INDEX_NAME, $this->synonymParams());
        $this->addAllProducts();
    }

    public function getSettings(string $indexName): void
    {
        try {
            $client = $this->setClient->indices()->getSettings([
                'index' => $indexName,
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
            /*$this->setClient->index([
                'index' => self::INDEX_NAME,
                'id'    => $product['id'],
                'body'  => $merge,
            ]);*/
        }
        dd($arr);
    }

    //index get all data
    #[NoReturn] public function getIndexAllData(string $indexName): void
    {
        $params = [
            'index' => $indexName,
            'body'  => [
                'query' => [
                    'match_all' => [],
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
        $items = $model->skip($skip)->take($limit)->get();
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
                'sort'      => [
                    '_score' => [
                        'order' => 'desc',
                    ],
                ],
            ],
        ];
    }

    private function transliterate($text): string
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
        //        if (preg_match('/[A-Za-z]/u', $text)) {
        return str_replace($lat, $cyr, $text);
        //        }
    }

}
