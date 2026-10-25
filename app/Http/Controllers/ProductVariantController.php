<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Product\StoreVariantRequest;
use App\Http\Requests\Product\UpdateVariantRequest;
use App\Models\MainStock;
use App\Models\Product;
use App\Services\VariantService;
use Brian2694\Toastr\Facades\Toastr;

/**
 * Product er variant (RAM/ROM/color): SKU, barcode, bikri dam, status, low-stock threshold.
 * Quantity ekhane bodlay na - stock purchase/sale/return/transfer/adjustment diye ashe.
 */
class ProductVariantController extends Controller
{
    public function __construct(private VariantService $variants) {}

    public function store(StoreVariantRequest $request, $id)
    {
        $product = Product::findOrFail($id);

        try {
            DB::transaction(fn () => $this->variants->create($product, $request->variantData()));
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        Toastr::success('Variant Added Successfully');
        return redirect()->route('product.show', $product->id);
    }

    public function update(UpdateVariantRequest $request, $id)
    {
        $stock = MainStock::findOrFail($id);

        try {
            DB::transaction(fn () => $this->variants->update($stock, $request->variantData()));
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        Toastr::success('Variant Updated Successfully');
        return redirect()->route('product.show', $stock->product_id);
    }

    public function changeStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:0,1']);
        $stock = MainStock::findOrFail($id);

        DB::transaction(fn () => $this->variants->update($stock, ['status' => (bool) $request->status]));

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Status updated successfully.']);
        }

        Toastr::success('Variant status updated');
        return redirect()->route('product.show', $stock->product_id);
    }

    public function delete($id)
    {
        $stock = MainStock::findOrFail($id);

        try {
            DB::transaction(fn () => $this->variants->delete($stock));
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        Toastr::success('Variant Deleted Successfully');
        return redirect()->route('product.show', $stock->product_id);
    }
}
