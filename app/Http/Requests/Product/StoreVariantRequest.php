<?php

namespace App\Http\Requests\Product;

use App\Models\MainStock;
use App\Models\Product;

class StoreVariantRequest extends VariantRequest
{
    private ?Product $current = null;

    protected function product(): Product
    {
        return $this->current ??= Product::findOrFail($this->route('id'));
    }

    protected function variant(): ?MainStock
    {
        return null;
    }

    protected function cost(): float
    {
        return (float) ($this->input('purchase_price') ?? $this->product()->purchase_price ?? 0);
    }

    public function rules(): array
    {
        // notun variant er shuru-r kena dam (stock 0, tai average er sathe gondogol hoy na)
        return parent::rules() + ['purchase_price' => ['nullable', 'numeric', 'min:0', 'max:99999999']];
    }

    public function variantData(): array
    {
        return parent::variantData() + $this->safe()->only(['purchase_price']);
    }
}
