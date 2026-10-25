<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Validation\Rule;

class StoreProductRequest extends ProductRequest
{
    protected function product(): ?Product
    {
        return null;
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // notun product shudhu active category/brand e
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('status', 1)],
            'brand_id'    => ['required', 'integer', Rule::exists('brands', 'id')->where('status', 1)],
            // default variant er SKU/barcode (khali dile SKU auto)
            'sku'         => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/', 'unique:main_stocks,sku'],
            'barcode'     => ['nullable', 'string', 'regex:/^[A-Za-z0-9\-]{4,64}$/', 'unique:main_stocks,barcode'],
        ]);
    }
}
