<?php

namespace App\Http\Requests\Product;

use App\Models\Product;

class UpdateProductRequest extends ProductRequest
{
    private ?Product $current = null;

    protected function product(): ?Product
    {
        return $this->current ??= Product::findOrFail($this->route('id'));
    }
}
