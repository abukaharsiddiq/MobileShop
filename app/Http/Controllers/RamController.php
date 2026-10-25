<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;
use App\Models\Ram;
use App\Services\CatalogUsage;

class RamController extends Controller
{
    private $heading;
    public function __construct()
    {
        $this->heading = 'Ram';
    }

    public function index(){
        $title= $this->heading;
        $name=$this->heading;
        $rams = Ram::oldest()->get();
        return view('back-end.product.ram.index', compact('rams', 'title', 'name'));
    }

    public function create(){
        $name = $this->heading;
        $title = $this->heading;
        return view('back-end.product.ram.create', compact('name', 'title'));
    }

    public function store(Request $request){

    $request->validate([
        'ram_name.*' => 'required|distinct|unique:rams,ram_name',
        'status.*' => 'required|in:0,1',
    ]);

    foreach ($request->ram_name as $index => $name) {
        Ram::create([
            'ram_name'   => $name,
            'slug'   => strtolower(str_replace(' ', '-', $name)),
            'status' => $request->status[$index] ?? 1,
        ]);
    }

    Toastr::success('Data Added Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('ram.index');
}


    public function edit($id){
        $name = $this->heading;
        $title = $this->heading;
        $ram = Ram::findOrFail($id);
        return view('back-end.product.ram.edit', compact('ram', 'title', 'name'));
    }

    public function update(Request $request, $id)
{
    $request->validate([
        'ram_name.*'   => 'required|distinct|unique:rams,ram_name,'.$id,
        'status.*' => 'required|in:0,1',
    ]);

    // Find the main category
    $ram = Ram::findOrFail($id);

    // Update the first row as main category
    $ram->ram_name   = $request->ram_name[0];
    $ram->slug   = strtolower(str_replace(' ', '-', $request->ram_name[0]));
    $ram->status = $request->status[0] ?? 1;
    $ram->save();

   
    if(count($request->ram_name) > 1) {
        for($i = 1; $i < count($request->ram_name); $i++) {
            // Check if you want to create new category or update existing subcategory
            Ram::create([
                'ram_name'   => $request->ram_name[$i],
                'slug'   => strtolower(str_replace(' ', '-', $request->ram_name[$i])),
                'status' => $request->status[$i] ?? 1,
            ]);
        }
    }

    Toastr::success('Data Updated Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('ram.index');
}

 public function changeStatus($id, Request $request) {
        $model = Ram::find($id);
        return changeStatusHelper($model,$request);
    }


    public function delete($id){
        $ram = Ram::findOrFail($id);

        // product/stock e use hole muchha jabe na (itihash bhange); inactive korte hobe
        if (CatalogUsage::inUse('ram', $ram->id)) {
            Toastr::error(CatalogUsage::message('RAM'), 'Error', ["positionClass" => "toast-top-right"]);
            return redirect()->route('ram.index');
        }

        $ram->delete();

        Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('ram.index');
    }
}
