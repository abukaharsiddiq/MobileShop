<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;

class AdditionalController extends Controller
{
    public function getProductDetails($id)
{
    // Eikhane product-er sathe variant relation gulo load hobe
    $product = Product::with(['ram', 'rom', 'color'])->findOrFail($id);
    return response()->json($product);
}

// Purchase form: product er active variant gulo (kon RAM/ROM/color kena hocche)
public function getProductVariants($id)
{
    $product = Product::findOrFail($id);
    $variants = \App\Models\MainStock::with(['ram', 'rom', 'color'])
        ->where('product_id', $product->id)
        ->where('status', 1)
        ->orderBy('id')
        ->get()
        ->map(fn ($v) => [
            'id'        => $v->id,
            'label'     => $v->attributeLabel() . ($v->sku ? " ({$v->sku})" : ''),
            'ram_id'    => $v->ram_id,
            'rom_id'    => $v->rom_id,
            'color_id'  => $v->color_id,
            'last_cost' => (float) $v->last_purchase_price,
            'is_default'=> $v->ram_id == $product->ram_id && $v->rom_id == $product->rom_id && $v->color_id == $product->color_id,
        ]);

    return response()->json($variants);
}

public function getProductsByCategory($id)
{
    $products = Product::where('category_id', $id)->get(['id', 'product_name']);
    return response()->json($products);
}

}
