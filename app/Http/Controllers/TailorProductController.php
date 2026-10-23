<?php

namespace App\Http\Controllers;

use App\Models\TailorProduct;
use App\Models\TailorCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Log;

class TailorProductController extends Controller
{
    private $heading;

    public function __construct()
    {
        $this->heading = 'Product';
    }

    public function index()
    {
        $title = $this->heading;
        $name = $this->heading;
        $products = TailorProduct::with('tailor_category')->latest()->get();
        return view('back-end.tailor.product.index', compact('products', 'name', 'title'));
    }

    public function create()
    {
        $title = $this->heading;
        $name = $this->heading;
        $categories = TailorCategory::all();
        return view('back-end.tailor.product.create', compact('categories', 'name', 'title'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'dress_name' => 'required|string|max:255',
            'tailor_category_id' => 'required',
            'original_image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
            'price' => 'required|numeric',
        ]);

        $product = new TailorProduct();
        $this->saveProduct($product, $request);

        Toastr::success('Product saved successfully!', 'Success');
        return redirect()->route('tailor.product.index');
    }

    public function edit($id)
    {
        $title = $this->heading;
        $name = $this->heading;
        $product = TailorProduct::findOrFail($id);
        $categories = TailorCategory::all();
        return view('back-end.tailor.product.edit', compact('product', 'categories', 'name', 'title'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'dress_name' => 'required|string|max:255',
            'tailor_category_id' => 'required',
            'price' => 'required|numeric',
        ]);

        $product = TailorProduct::findOrFail($id);
        $this->saveProduct($product, $request, true);

        Toastr::success('Product updated successfully!', 'Success');
        return redirect()->route('tailor.product.index');
    }

    // Common function for Store & Update
    private function saveProduct($product, $request, $isUpdate = false)
    {
        $product->dress_name = $request->dress_name;
        $product->tailor_category_id = $request->tailor_category_id;
        $product->price = $request->price;
        $product->description = $request->description;

        if ($request->hasFile('original_image')) {
            // পুরাতন ছবি ডিলিট করা
            if ($isUpdate) {
                if ($product->original_image && file_exists(public_path($product->original_image))) @unlink(public_path($product->original_image));
                if ($product->garment_image_transparent && file_exists(public_path($product->garment_image_transparent))) @unlink(public_path($product->garment_image_transparent));
            }

            $image = $request->file('original_image');
            $imageName = time() . '_main.' . $image->extension();
            $originalPath = public_path('back-end/tailor/products/original');
            
            if (!file_exists($originalPath)) mkdir($originalPath, 0777, true);
            
            $image->move($originalPath, $imageName);
            $product->original_image = 'back-end/tailor/products/original/' . $imageName;

            // ব্যাকগ্রাউন্ড রিমুভ প্রসেস
            $garmentName = time() . '_garment.png';
            $garmentFolder = public_path('back-end/tailor/products/garments');
            if (!file_exists($garmentFolder)) mkdir($garmentFolder, 0777, true);

            $inputPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $originalPath . DIRECTORY_SEPARATOR . $imageName);
            $outputPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $garmentFolder . DIRECTORY_SEPARATOR . $garmentName);
            $scriptPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, public_path('scripts/bg_remover.py'));
            $pythonPath = "C:\\Users\\abuka\\AppData\\Local\\Programs\\Python\\Python312\\python.exe";

            $command = "\"$pythonPath\" \"$scriptPath\" \"$inputPath\" \"$outputPath\"";

            try {
                $result = Process::timeout(120)->run($command);
                if ($result->successful() || file_exists($outputPath)) {
                    $product->garment_image_transparent = 'back-end/tailor/products/garments/' . $garmentName;
                } else {
                    // Fallback to exec
                    exec($command);
                    if (file_exists($outputPath)) {
                        $product->garment_image_transparent = 'back-end/tailor/products/garments/' . $garmentName;
                    }
                }
            } catch (\Exception $e) {
                Log::error("Python Error: " . $e->getMessage());
            }
        }

        $product->save();
    }

    public function delete($id)
    {
        $product = TailorProduct::findOrFail($id);
        if ($product->original_image && file_exists(public_path($product->original_image))) @unlink(public_path($product->original_image));
        if ($product->garment_image_transparent && file_exists(public_path($product->garment_image_transparent))) @unlink(public_path($product->garment_image_transparent));
        
        $product->delete();
        Toastr::success('Product deleted successfully!');
        return redirect()->back();
    }

    public function processTryOn(Request $request) {
    $customerImage = $request->file('user_photo'); // কাস্টমারের ছবি
    $garmentImagePath = public_path($request->product_garment_path); // আপনার তৈরি করা ট্রান্সপারেন্ট ড্রেস
    
    $outputPath = public_path('back-end/tryon/result_' . time() . '.jpg');
    
    $pythonPath = "C:\\Users\\abuka\\AppData\\Local\\Programs\\Python\\Python312\\python.exe";
    $scriptPath = public_path('scripts/design_overlay.py');

    $command = "\"$pythonPath\" \"$scriptPath\" \"$customerImagePath\" \"$garmentImagePath\" \"$outputPath\"";
    
    exec($command);
    
    return response()->json(['result_url' => asset('back-end/tryon/' . basename($outputPath))]);
}

public function tryOn(Request $request)
{
    $request->validate([
        'customer_photo' => 'required|image',
        'product_id' => 'required'
    ]);

    $product = TailorProduct::findOrFail($request->product_id);
    
    // ১. কাস্টমারের ছবি সেভ করা
    $customerImage = $request->file('customer_photo');
    $customerPath = public_path('temp/customer_' . time() . '.jpg');
    $customerImage->move(public_path('temp'), basename($customerPath));

    // ২. আউটপুট পাথ তৈরি
    $outputPath = public_path('uploads/tryon/result_' . time() . '.jpg');
    if (!file_exists(public_path('uploads/tryon'))) mkdir(public_path('uploads/tryon'), 0777, true);

    // ৩. পাইথন রান করা
    $pythonPath = "C:\\Users\\abuka\\AppData\\Local\\Programs\\Python\\Python312\\python.exe";
    $scriptPath = public_path('scripts/ai_tryon.py');
    $garmentPath = public_path($product->garment_image_transparent);

    $command = "\"$pythonPath\" \"$scriptPath\" \"$customerPath\" \"$garmentPath\" \"$outputPath\"";
    
    \Illuminate\Support\Facades\Process::timeout(120)->run($command);

    return view('frontend.tryon_result', [
        'result_image' => asset('uploads/tryon/' . basename($outputPath))
    ]);
}

}