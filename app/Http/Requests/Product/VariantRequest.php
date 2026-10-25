<?php

namespace App\Http\Requests\Product;

use App\Models\MainStock;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Variant (main_stocks) er SKU, barcode, dam, status, threshold. Quantity ekhane kokhono ase na:
 * stock shudhu purchase/sale/return/transfer/adjustment diye bodlay.
 */
abstract class VariantRequest extends FormRequest
{
    abstract protected function product(): Product;

    abstract protected function variant(): ?MainStock;

    // kena dam jar sathe bikri dam tulona hoy
    abstract protected function cost(): float;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku'     => $this->filled('sku') ? strtoupper(trim($this->input('sku'))) : null,
            'barcode' => $this->filled('barcode') ? trim($this->input('barcode')) : null,
            // form e status na thakle ager ta (notun hole active)
            'status'  => $this->has('status') ? $this->boolean('status') : (bool) ($this->variant()?->status ?? true),
        ]);
    }

    public function rules(): array
    {
        $ignore = $this->variant()?->id;

        return [
            'ram_id'              => ['nullable', 'integer', 'exists:rams,id'],
            'rom_id'              => ['nullable', 'integer', 'exists:roms,id'],
            'color_id'            => ['nullable', 'integer', 'exists:colors,id'],
            'sku'                 => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/', Rule::unique('main_stocks', 'sku')->ignore($ignore)],
            'barcode'             => ['nullable', 'string', 'regex:/^[A-Za-z0-9\-]{4,64}$/', Rule::unique('main_stocks', 'barcode')->ignore($ignore)],
            'sale_price'          => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status'              => ['boolean'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = $this->product()->type;
            if (!Product::typeAllowsMemory($type) && ($this->filled('ram_id') || $this->filled('rom_id'))) {
                $validator->errors()->add('ram_id', 'An accessory variant cannot have RAM or storage.');
            }
            if ($this->checksMemory() && Product::typeRequiresMemory($type) && (!$this->filled('ram_id') || !$this->filled('rom_id'))) {
                $validator->errors()->add('rom_id', 'RAM and storage are required for a smartphone variant.');
            }

            $variant = $this->variant();
            $priceChanged = !$variant || round((float) $variant->sale_price, 2) !== round((float) $this->input('sale_price'), 2);
            if ($priceChanged && (float) $this->input('sale_price') < $this->cost() && !$this->user()->can('sell below cost')) {
                $validator->errors()->add('sale_price', 'Sale price cannot be lower than the purchase cost (' . number_format($this->cost(), 2) . ').');
            }
        }];
    }

    protected function checksMemory(): bool
    {
        return true;
    }

    // service e jabe shudhu egulo (quantity/kena dam form theke ase na)
    public function variantData(): array
    {
        return $this->safe()->only(['ram_id', 'rom_id', 'color_id', 'sku', 'barcode', 'sale_price', 'status', 'low_stock_threshold']);
    }

    public function attributes(): array
    {
        return ['rom_id' => 'storage', 'ram_id' => 'RAM', 'color_id' => 'color'];
    }
}
