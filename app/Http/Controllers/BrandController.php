<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;
use App\Models\Brand;

class BrandController extends Controller
{
    private $heading;
    public function __construct()
    {
        $this->heading = 'Brand';
    }

    public function index(){
        $title= $this->heading;
        $name=$this->heading;
        $brands = Brand::oldest()->get();
        return view('back-end.product.brand.index', compact('brands', 'title', 'name'));
    }

    public function create(){
        $name = $this->heading;
        $title = $this->heading;
        return view('back-end.product.brand.create', compact('name', 'title'));
    }

    public function store(Request $request){

    $request->validate([
        'brand_name.*' => 'required|distinct|unique:brands,brand_name',
        'status.*' => 'required|in:0,1',
    ]);

    foreach ($request->brand_name as $index => $name) {
        Brand::create([
            'brand_name'   => $name,
            'slug'   => strtolower(str_replace(' ', '-', $name)),
            'status' => $request->status[$index] ?? 1,
        ]);
    }

    Toastr::success('Data Added Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('brand.index');
}


    public function edit($id){
        $name = $this->heading;
        $title = $this->heading;
        $brand = Brand::findOrFail($id);
        return view('back-end.product.brand.edit', compact('brand', 'title', 'name'));
    }

    public function update(Request $request, $id)
{
    $request->validate([
        'brand_name.*'   => 'required|distinct|unique:brands,brand_name,'.$id,
        'status.*' => 'required|in:0,1',
    ]);

    // Find the main category
    $brand = Brand::findOrFail($id);

    // Update the first row as main category
    $brand->brand_name   = $request->brand_name[0];
    $brand->slug   = strtolower(str_replace(' ', '-', $request->brand_name[0]));
    $brand->status = $request->status[0] ?? 1;
    $brand->save();

   
    if(count($request->brand_name) > 1) {
        for($i = 1; $i < count($request->brand_name); $i++) {
            // Check if you want to create new category or update existing subcategory
            Brand::create([
                'brand_name'   => $request->brand_name[$i],
                'slug'   => strtolower(str_replace(' ', '-', $request->brand_name[$i])),
                'status' => $request->status[$i] ?? 1,
            ]);
        }
    }

    Toastr::success('Data Updated Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('brand.index');
}

 public function changeStatus($id, Request $request) {
        $model = Brand::find($id);
        return changeStatusHelper($model,$request);
    }


    public function delete($id){
        $category = Brand::findOrFail($id);
        $category->delete();

        Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('brand.index');
    }
}
