<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Product master er validation (Store/Update duitar shared niyom).
 * Permission route e (can:create product / can:update product) check hoy.
 */
abstract class ProductRequest extends FormRequest
{
    abstract protected function product(): ?Product;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // purono form e "accessory_note" naam chilo, column chilo na (lekha harato)
            'description' => $this->input('description', $this->input('accessory_note')),
            'unit'        => $this->input('unit') ?: 'pcs',
            'sku'         => $this->filled('sku') ? strtoupper(trim($this->input('sku'))) : null,
            'barcode'     => $this->filled('barcode') ? trim($this->input('barcode')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'type'                => ['required', Rule::in(array_keys(Product::TYPES))],
            'product_name'        => ['required', 'string', 'max:255'],
            'model_number'        => ['required', 'string', 'max:100'],
            'category_id'         => ['required', 'integer', 'exists:categories,id'],
            'brand_id'            => ['required', 'integer', 'exists:brands,id'],
            'ram_id'              => ['nullable', 'integer', 'exists:rams,id'],
            'rom_id'              => ['nullable', 'integer', 'exists:roms,id'],
            'color_id'            => ['nullable', 'integer', 'exists:colors,id'],
            'purchase_price'      => ['required', 'numeric', 'min:0', 'max:99999999'],
            'sale_price'          => ['required', 'numeric', 'min:0', 'max:99999999'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'warranty_months'     => ['nullable', 'integer', 'min:0', 'max:120'],
            'description'         => ['nullable', 'string', 'max:2000'],
            'unit'                => ['nullable', 'string', 'max:20'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $product = $this->product();
            $type = $this->input('type');

            // accessory te RAM/storage na; smart phone e lagbe (purono product er khali RAM/ROM edit atkay na)
            if (!Product::typeAllowsMemory($type)) {
                foreach (['ram_id' => 'RAM', 'rom_id' => 'Storage'] as $field => $label) {
                    if ($this->filled($field)) {
                        $validator->errors()->add($field, "{$label} cannot be set for an accessory.");
                    }
                }
            } elseif (Product::typeRequiresMemory($type)) {
                $legacyWithoutMemory = $product && $product->type === $type && !$product->ram_id && !$product->rom_id
                    && !$this->filled('ram_id') && !$this->filled('rom_id');
                if (!$legacyWithoutMemory) {
                    foreach (['ram_id' => 'RAM', 'rom_id' => 'Storage'] as $field => $label) {
                        if (!$this->filled($field)) {
                            $validator->errors()->add($field, "{$label} is required for a smartphone.");
                        }
                    }
                }
            }

            // ekoi brand e ekoi model + type duibar na (purono duplicate edit atkay na, jodi model na bodlay)
            $identityChanged = !$product || $product->model_number !== $this->input('model_number')
                || (int) $product->brand_id !== (int) $this->input('brand_id') || $product->type !== $type;
            if ($identityChanged) {
                $exists = Product::where('brand_id', $this->input('brand_id'))
                    ->where('model_number', $this->input('model_number'))
                    ->where('type', $type)
                    ->when($product, fn ($q) => $q->where('id', '!=', $product->id))
                    ->exists();
                if ($exists) {
                    $validator->errors()->add('model_number', 'A product with this brand, model and type already exists. Add a variant to it instead.');
                }
            }

            // kena damer niche bikri dam shudhu 'sell below cost' permission thakle
            $pricesChanged = !$product || round((float) $product->sale_price, 2) !== round((float) $this->input('sale_price'), 2)
                || round((float) $product->purchase_price, 2) !== round((float) $this->input('purchase_price'), 2);
            if ($pricesChanged && (float) $this->input('sale_price') < (float) $this->input('purchase_price')
                && !$this->user()->can('sell below cost')) {
                $validator->errors()->add('sale_price', 'Sale price cannot be lower than the purchase price.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['rom_id' => 'storage', 'ram_id' => 'RAM', 'color_id' => 'color', 'category_id' => 'category', 'brand_id' => 'brand'];
    }
}
