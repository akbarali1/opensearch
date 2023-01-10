<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductModel extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE         = 1;
    public const ACTIVE_PRODUCT_STATUS = 1;
    public const PRODUCT_LIMIT         = 5;
    public const QUANTITY_ZERO         = 0;
    public const MIN_QUANTITY          = 1;
    public const PRICE_DIFFERENCE      = 200000;
    public const INTEND_CACHE_PREFIX   = "INTEND_PRODUCTS";
    //const INTEND_CACHE_PREFIX   = "INTEND_PRICE_PRODUCT";

    protected $table = 'products';

    protected $fillable = [
        'id',
        'user_id',
        'name',
        'slug',
        'content',
        'meta_title',
        'meta_description',
        'meta_keyword',
        'model',
        'sku',
        'price',
        'mxik',
        'quantity',
        'min_quantity',
        'warehouse',
        'length',
        'width',
        'height',
        'length_type_id',
        'weight',
        'weight_type_id',
        'status',
        'sort_product',
        'manufacturer_id',
        'supplier_id',
        'viewed',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'name'             => 'array',
        'slug'             => 'array',
        'description'      => 'array',
        'content'          => 'array',
        'meta_title'       => 'array',
        'meta_description' => 'array',
        'meta_keyword'     => 'array',
    ];

}
