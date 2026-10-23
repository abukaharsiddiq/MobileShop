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

public function getProductsByCategory($id)
{
    $products = Product::where('category_id', $id)->get(['id', 'product_name']);
    return response()->json($products);
}

}
