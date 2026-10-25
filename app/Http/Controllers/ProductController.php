<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Ram;
use App\Models\Rom;
use App\Models\Product;
use App\Models\MainStock;
use App\Models\PriceHistory;
use App\Services\ProductService;
use Brian2694\Toastr\Facades\Toastr;


/**
 * Product = model master. Stock protiti variant (main_stocks) e; variant er kaj ProductVariantController e.
 */
class ProductController extends Controller
{
    private $heading;

    public function __construct(private ProductService $products)
    {
        $this->heading = 'Product';
    }

    public function index()
    {
        $name=$this->heading;
        $title = $this->heading;
         $products = Product::with(['category','brand','ram','rom','color', 'variants.product'])
            ->withCount('variants')
            ->withSum('variants', 'quantity')
            ->get();
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

   public function store(StoreProductRequest $request)
{
    try {
        // product + default variant (stock 0; stock ashe purchase/adjustment diye)
        $product = $this->products->create($request->validated());
    } catch (\Exception $e) {
        return back()->with('error', errorMessage($e))->withInput();
    }

    Toastr::success('Product Added Successfully');
    return redirect()->route('product.show', $product->id);
}

    public function show(Request $request, $id)
    {
        $name = $this->heading;
        $title = 'Product Details';
        $product = Product::with(['category', 'brand'])->findOrFail($id);
        $variants = MainStock::with(['ram', 'rom', 'color', 'product'])
            ->withCount(['imeis as in_stock_imeis' => fn ($q) => $q->where('status', 1)])
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get();

        // kena dam/itihash shudhu jara kinte ba product bodlate pare
        $canSeeCost = $request->user()->canAny(['create purchase', 'update product']);
        $histories = $canSeeCost
            ? PriceHistory::with(['user', 'mainStock.ram', 'mainStock.rom', 'mainStock.color'])->where('product_id', $product->id)->latest('id')->limit(50)->get()
            : collect();

        $rams = Ram::where('status', 1)->oldest()->get();
        $roms = Rom::where('status', 1)->oldest()->get();
        $colors = Color::where('status', 1)->oldest()->get();

        return view('back-end.product.product.show', compact('name', 'title', 'product', 'variants', 'histories', 'canSeeCost', 'rams', 'roms', 'colors'));
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

public function update(UpdateProductRequest $request, $id)
{
    try {
        // master tottho; stock er quantity ar kena dam (average) kokhono ekhane bodlay na
        $product = $this->products->update(Product::findOrFail($id), $request->validated());
    } catch (\Exception $e) {
        return back()->with('error', errorMessage($e))->withInput();
    }

    Toastr::success('Product Updated Successfully');
    return redirect()->route('product.show', $product->id);
}


public function delete($id)
{
    try {
        // kena-becha/stock er itihash thakle muchha jay na (inactive korte hobe)
        $this->products->delete(Product::findOrFail($id));
    } catch (\Exception $e) {
        return back()->with('error', errorMessage($e));
    }

    Toastr::success('Data Deleted Successfully');
    return redirect()->route('product.index');
}


 public function changeStatus($id, Request $request) {
        $request->validate(['status' => 'required|in:0,1']);
        $product = Product::findOrFail($id);
        $this->products->setStatus($product, (bool) $request->status);

        return response()->json(['success' => true, 'message' => 'Status updated successfully.']);
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
