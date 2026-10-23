<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;
use App\Models\Color;

class ColorController extends Controller
{
     private $heading;
    public function __construct()
    {
        $this->heading = 'Color';
    }

    public function index(){
        $title= $this->heading;
        $name=$this->heading;
        $colors = Color::oldest()->get();
        return view('back-end.product.color.index', compact('colors', 'title', 'name'));
    }

    public function create(){
        $name = $this->heading;
        $title = $this->heading;
        return view('back-end.product.color.create', compact('name', 'title'));
    }

    public function store(Request $request){

    $request->validate([
        'color_name.*' => 'required|distinct|unique:colors,color_name',
        'status.*' => 'required|in:0,1',
    ]);

    foreach ($request->color_name as $index => $name) {
        Color::create([
            'color_name'   => $name,
            'slug'   => strtolower(str_replace(' ', '-', $name)),
            'status' => $request->status[$index] ?? 1,
        ]);
    }

    Toastr::success('Data Added Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('color.index');
}


    public function edit($id){
        $name = $this->heading;
        $title = $this->heading;
        $color = Color::findOrFail($id);
        return view('back-end.product.color.edit', compact('color', 'title', 'name'));
    }

    public function update(Request $request, $id)
{
    $request->validate([
        'color_name.*'   => 'required|distinct|unique:colors,color_name,'.$id,
        'status.*' => 'required|in:0,1',
    ]);

    // Find the main category
    $color = Color::findOrFail($id);

    // Update the first row as main category
    $color->color_name   = $request->color_name[0];
    $color->slug   = strtolower(str_replace(' ', '-', $request->color_name[0]));
    $color->status = $request->status[0] ?? 1;
    $color->save();

   
    if(count($request->color_name) > 1) {
        for($i = 1; $i < count($request->color_name); $i++) {
            // Check if you want to create new category or update existing subcategory
            Color::create([
                'color_name'   => $request->color_name[$i],
                'slug'   => strtolower(str_replace(' ', '-', $request->color_name[$i])),
                'status' => $request->status[$i] ?? 1,
            ]);
        }
    }

    Toastr::success('Data Updated Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('color.index');
}

 public function changeStatus($id, Request $request) {
        $model = Color::find($id);
        return changeStatusHelper($model,$request);
    }


    public function delete($id){
        $color = Color::findOrFail($id);
        $color->delete();

        Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('color.index');
    }
}
