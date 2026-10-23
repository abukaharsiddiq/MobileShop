<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;
use App\Models\Category;

class CategoryController extends Controller
{
    private $heading;
    public function __construct()
    {
        $this->heading = 'Category';
    }

    public function index(){
        $title= $this->heading;
        $name=$this->heading;
        $categories = Category::oldest()->get();
        return view('back-end.product.category.index', compact('categories', 'title', 'name'));
    }

    public function create(){
        $name = $this->heading;
        $title = $this->heading;
        return view('back-end.product.category.create', compact('name', 'title'));
    }

    public function store(Request $request){

    $request->validate([
        'name.*' => 'required|distinct|unique:categories,name',
        'status.*' => 'required|in:0,1',
    ]);

    foreach ($request->name as $index => $name) {
        Category::create([
            'name'   => $name,
            'slug'   => strtolower(str_replace(' ', '-', $name)),
            'status' => $request->status[$index] ?? 1,
        ]);
    }

    Toastr::success('Categories Added Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('category.index');
}


    public function edit($id){
        $name = $this->heading;
        $title = $this->heading;
        $category = Category::findOrFail($id);
        return view('back-end.product.category.edit', compact('category', 'title', 'name'));
    }

    public function update(Request $request, $id)
{
    $request->validate([
        'name.*'   => 'required|distinct|unique:categories,name,'.$id,
        'status.*' => 'required|in:0,1',
    ]);

    // Find the main category
    $category = Category::findOrFail($id);

    // Update the first row as main category
    $category->name   = $request->name[0];
    $category->slug   = strtolower(str_replace(' ', '-', $request->name[0]));
    $category->status = $request->status[0] ?? 1;
    $category->save();

   
    if(count($request->name) > 1) {
        for($i = 1; $i < count($request->name); $i++) {
            // Check if you want to create new category or update existing subcategory
            Category::create([
                'name'   => $request->name[$i],
                'slug'   => strtolower(str_replace(' ', '-', $request->name[$i])),
                'status' => $request->status[$i] ?? 1,
            ]);
        }
    }

    Toastr::success('Category Updated Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('category.index');
}

 public function changeStatus($id, Request $request) {
        $model = Category::find($id);
        return changeStatusHelper($model,$request);
    }


    public function delete($id){
        $category = Category::findOrFail($id);
        $category->delete();

        Toastr::success('Category Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('category.index');
    }
}
