<?php

namespace App\Http\Requests\Product;

use App\Models\MainStock;
use App\Models\Product;

class UpdateVariantRequest extends VariantRequest
{
    private ?MainStock $current = null;

    protected function variant(): ?MainStock
    {
        return $this->current ??= MainStock::with('product')->findOrFail($this->route('id'));
    }

    protected function product(): Product
    {
        return $this->variant()->product;
    }

    protected function cost(): float
    {
        return (float) $this->variant()->average_purchase_price;
    }

    // purono variant e RAM/ROM na thakle (legacy) edit atkano hoy na; attribute pathale tobei check
    protected function checksMemory(): bool
    {
        return $this->hasAny(['ram_id', 'rom_id']);
    }

    public function variantData(): array
    {
        $data = parent::variantData();

        // form e attribute na pathale bodlano hoy na
        foreach (['ram_id', 'rom_id', 'color_id'] as $key) {
            if (!$this->has($key)) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
