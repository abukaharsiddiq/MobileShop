<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;
use App\Models\Rom;

class RomController extends Controller
{
    private $heading;
    public function __construct()
    {
        $this->heading = 'Rom';
    }

    public function index(){
        $title= $this->heading;
        $name=$this->heading;
        $roms = Rom::oldest()->get();
        return view('back-end.product.rom.index', compact('roms', 'title', 'name'));
    }

    public function create(){
        $name = $this->heading;
        $title = $this->heading;
        return view('back-end.product.rom.create', compact('name', 'title'));
    }

    public function store(Request $request){

    $request->validate([
        'rom_name.*' => 'required|distinct|unique:roms,rom_name',
        'status.*' => 'required|in:0,1',
    ]);

    foreach ($request->rom_name as $index => $name) {
        Rom::create([
            'rom_name'   => $name,
            'slug'   => strtolower(str_replace(' ', '-', $name)),
            'status' => $request->status[$index] ?? 1,
        ]);
    }

    Toastr::success('Data Added Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('rom.index');
}


    public function edit($id){
        $name = $this->heading;
        $title = $this->heading;
        $rom = Rom::findOrFail($id);
        return view('back-end.product.rom.edit', compact('rom', 'title', 'name'));
    }

    public function update(Request $request, $id)
{
    $request->validate([
        'rom_name.*'   => 'required|distinct|unique:roms,rom_name,'.$id,
        'status.*' => 'required|in:0,1',
    ]);

    // Find the main category
    $rom = Rom::findOrFail($id);

    // Update the first row as main category
    $rom->rom_name   = $request->rom_name[0];
    $rom->slug   = strtolower(str_replace(' ', '-', $request->rom_name[0]));
    $rom->status = $request->status[0] ?? 1;
    $rom->save();

   
    if(count($request->rom_name) > 1) {
        for($i = 1; $i < count($request->rom_name); $i++) {
            // Check if you want to create new category or update existing subcategory
            Rom::create([
                'rom_name'   => $request->rom_name[$i],
                'slug'   => strtolower(str_replace(' ', '-', $request->rom_name[$i])),
                'status' => $request->status[$i] ?? 1,
            ]);
        }
    }

    Toastr::success('Data Updated Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('rom.index');
}

 public function changeStatus($id, Request $request) {
        $model = Rom::find($id);
        return changeStatusHelper($model,$request);
    }


    public function delete($id){
        $ram = Rom::findOrFail($id);
        $ram->delete();

        Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('rom.index');
    }
}
