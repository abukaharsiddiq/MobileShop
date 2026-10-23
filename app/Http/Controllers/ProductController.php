<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Ram;
use App\Models\Rom;
use App\Models\Product;
use App\Models\MainStock;
use App\Models\PurchaseDetail;
use App\Models\SaleDetail;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;


class ProductController extends Controller
{
    private $heading;

    public function __construct()
    {
        $this->heading = 'Product';
    }

    public function index()
    {
        $name=$this->heading;
        $title = $this->heading;
         $products = Product::with(['category','brand','ram','rom','color'])->get();
        return view('back-end.product.product.index', compact('title', 'name','products'));
    }

     public function create()
    {
        $name=$this->heading;
        $title = $this->heading;
        $categories = Category::where('status', 1)->oldest()->get();
        $brands = Brand::where('status', 1)->oldest()->get();
        $colors = Color::where('status', 1)->oldest()->get();
        $rams = Ram::where('status', 1)->oldest()->get();
        $roms = Rom::where('status', 1)->oldest()->get();
        return view('back-end.product.product.create', compact('title', 'name', 'categories', 'brands', 'colors', 'rams', 'roms'));
    }

   public function store(Request $request)
{

    // 1. Validation (RAM, ROM, Color nullable thakbe accessories er jonno)
    $request->validate([
        'type'         => 'required|in:' . implode(',', array_keys(Product::TYPES)),
        'product_name' => 'required|string|max:255',
        'model_number' => 'required|string|max:255',
        'category_id'  => 'required|exists:categories,id',
        'brand_id'     => 'required|exists:brands,id',
        'ram_id'       => 'nullable|exists:rams,id',
        'rom_id'       => 'nullable|exists:roms,id',
        'color_id'     => 'nullable|exists:colors,id',
        'purchase_price' => 'required|numeric|min:0',
    ]);

    DB::beginTransaction();

    try {
        // 2. Product Table Entry
        $product = Product::create([
            'type'             => $request->type,
            'product_name'     => $request->product_name,
            'model_number'     => $request->model_number,
            'category_id'      => $request->category_id,
            'brand_id'         => $request->brand_id,
            'ram_id'           => $request->ram_id,
            'rom_id'           => $request->rom_id,
            'color_id'         => $request->color_id,
            'purchase_price'   => $request->purchase_price,
            'sale_price'       => $request->sale_price,
            'unit'             => $request->unit,
            'status'           => 1,
        ]);

            MainStock::create([
                'category_id'            => $request->category_id,
                'product_id'             => $product->id,
                'ram_id'                 => $request->ram_id,
                'rom_id'                 => $request->rom_id,
                'color_id'               => $request->color_id,
                'quantity'               => 0,
                'average_purchase_price' => $request->purchase_price,
                'last_purchase_price'    => $request->purchase_price,
                'sale_price'             => $request->sale_price,
            ]);
     

        DB::commit();
        Toastr::success('Product and Opening Stock Added Successfully');
        return redirect()->route('product.index');

    } catch (\Exception $e) {
        DB::rollBack();
        // Technical error handle kora
        Toastr::error(errorMessage($e), 'Error');
        return back()->withInput();
    }
}


    public function edit($id)
{
    $name=$this->heading;
    $title = $this->heading;
    $product = Product::findOrFail($id);
    $categories = Category::all();
    $brands = Brand::all();
    $rams = Ram::all();
    $roms = Rom::all();
    $colors = Color::all();

    return view('back-end.product.product.edit', compact('product','categories','brands','rams','roms','colors','name','title'));
}

public function update(Request $request, $id)
{
    $request->validate([
        'type' => 'required|in:' . implode(',', array_keys(Product::TYPES)),
    ]);

    DB::beginTransaction();

    try {
        $product = Product::findOrFail($id);
        $oldVariant = $product->only(['ram_id', 'rom_id', 'color_id']);

        // Stock e IMEI shoho phone thakle product ke accessory banano jabe na
        if ($request->type === 'accessory' && $product->imeis()->where('status', \App\Models\ProductImei::STATUS_IN_STOCK)->exists()) {
            throw new \Exception('This product has devices with IMEI in stock and cannot be changed to an accessory.');
        }

        // 1. Basic Product Info Update
        $product->update([
            'type'             => $request->type,
            'product_name'     => $request->product_name,
            'model_number'     => $request->model_number,
            'category_id'      => $request->category_id,
            'brand_id'         => $request->brand_id,
            'ram_id'           => $request->ram_id,
            'rom_id'           => $request->rom_id,
            'color_id'         => $request->color_id,
            'purchase_price'   => $request->purchase_price,
            'sale_price'       => $request->sale_price,
            'unit'             => $request->unit,
            'accessory_note'   => $request->accessory_note,
        ]);

        // 3. Variant-wise Stock Update
        // Stock er quantity, average purchase price ar IMEI kokhono overwrite hobe na.
        MainStock::where('product_id', $product->id)->update(['category_id' => $request->category_id]);

        $newVariant = [
            'ram_id'   => $request->ram_id,
            'rom_id'   => $request->rom_id,
            'color_id' => $request->color_id,
        ];

        $stock = MainStock::where('product_id', $product->id)->where($newVariant)->first();

        if ($stock) {
            $stock->update(['sale_price' => $request->sale_price]);
        } else {
            // Purono variant er stock jodi kokhono use na hoye thake (qty 0, IMEI/kena-becha nei)
            // tobe setai notun variant e bodle deya hoy; noile notun stock row toiri hoy ar purono ta thik thake
            $oldStock = MainStock::where('product_id', $product->id)->where($oldVariant)->first();

            $oldStockUnused = $oldStock
                && $oldStock->quantity == 0
                && !$oldStock->imeis()->exists()
                && !PurchaseDetail::where('product_id', $product->id)->where($oldVariant)->exists()
                && !SaleDetail::where('product_id', $product->id)->where($oldVariant)->exists();

            if ($oldStockUnused) {
                $oldStock->update($newVariant + [
                    'average_purchase_price' => $request->purchase_price,
                    'last_purchase_price'    => $request->purchase_price,
                    'sale_price'             => $request->sale_price,
                ]);
            } else {
                MainStock::create($newVariant + [
                    'product_id'             => $product->id,
                    'category_id'            => $request->category_id,
                    'quantity'               => 0,
                    'average_purchase_price' => $request->purchase_price,
                    'last_purchase_price'    => $request->purchase_price,
                    'sale_price'             => $request->sale_price,
                ]);
            }
        }

        DB::commit();
        Toastr::success('Product and Stock Updated Successfully');
        return redirect()->route('product.index');

    } catch (\Exception $e) {
        DB::rollBack();
        Toastr::error(errorMessage($e), 'Error');
        return back();
    }
}


public function delete($id)
{
    DB::beginTransaction();

    try {

        $product = Product::findOrFail($id);

        MainStock::where('product_id', $product->id)->delete();

        $product->delete();

        DB::commit();

        Toastr::success('Data Deleted Successfully');
        return redirect()->route('product.index');

    } catch (\Exception $e) {

        DB::rollBack();
        Toastr::error(errorMessage($e), 'Error');
        return back();
    }
}


 public function changeStatus($id, Request $request) {
        $model = Product::find($id);
        return changeStatusHelper($model,$request);
    }


   public function voice_search()
    {
        $name=$this->heading;
        $title = $this->heading;
         $products = Product::with(['category','brand','ram','rom','color'])->get();
        return view('back-end.product.product.speak-voice-search', compact('title', 'name','products'));
    }


    public function printBarcode($id)
{
    $product = Product::with('imeis')->findOrFail($id);
    return view('back-end.product.product.print-barcode', compact('product'));
}

    
}
