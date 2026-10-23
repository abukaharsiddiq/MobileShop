<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Brian2694\Toastr\Facades\Toastr;

// Dokan/warehouse er branch (branches table). User ar stock/IMEI ei branch er sathe jukto thake.
class BranchController extends Controller
{

    private $heading;

    public function __construct(){
        $this->heading = "Branch";
    }

    public function index(){
        $title= $this->heading;
        $name=$this->heading;
        $branches = Branch::orderByDesc('is_default')->orderBy('name')->get();
        return view('back-end.branch.index', compact('branches', 'title', 'name'));
    }

    public function create(){
        $title= $this->heading;
        $name=$this->heading;
        return view('back-end.branch.create', compact('title', 'name'));
    }

    public function store(Request $request){
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $branch = Branch::create($data + ['status' => 1]);
            $this->keepSingleDefault($branch);
        });

        Toastr::success('Data Added Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('branch.index');
    }

    public function edit($id){
        $title='Branch Edit';
        $name='Branch';
        $branch = Branch::findOrFail($id);
        return view('back-end.branch.edit', compact('branch', 'title', 'name'));
    }

    public function update(Request $request)
    {
        $branch = Branch::findOrFail($request->id);
        $data = $this->validated($request, $branch->id);
        $data['status'] = $request->boolean('status', true);

        DB::transaction(function () use ($branch, $data) {
            $branch->update($data);
            $this->keepSingleDefault($branch);
        });

        Toastr::success('Data Updated Successfully', 'Success', [
            "positionClass" => "toast-top-right"
        ]);

        return redirect()->route('branch.index');
    }

    public function delete($id){
        $branch = Branch::findOrFail($id);

        // Branch kothao use hoye thakle muchha jabe na (stock/IMEI itihash e branch harabe) - inactive korte hobe
        $usage = [
            'users'             => 'users',
            'product_imeis'     => 'IMEI records',
            'stock_adjustments' => 'stock corrections',
            'stock_movements'   => 'stock movements',
            'imei_movements'    => 'IMEI history',
        ];
        foreach ($usage as $table => $label) {
            if (Schema::hasTable($table) && DB::table($table)->where('branch_id', $branch->id)->exists()) {
                Toastr::error("This branch is used in {$label} and cannot be deleted. Set it to Inactive instead.", 'Error');
                return redirect()->route('branch.index');
            }
        }

        DB::transaction(function () use ($branch) {
            $branch->delete();
            $this->keepSingleDefault(null);
        });
        Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('branch.index');
    }

    public function changeStatus($id, Request $request) {
        $model = Branch::find($id);
        return changeStatusHelper($model,$request);
    }

    private function validated(Request $request, $ignoreId = null)
    {
        $request->validate([
            'branch_name'    => 'required|string|max:255|unique:branches,name' . ($ignoreId ? ',' . $ignoreId : ''),
            'branch_code'    => 'nullable|string|max:50',
            'branch_email'   => 'nullable|email|max:255',
            'branch_phone'   => 'required|string|max:50',
            'branch_address' => 'required|string|max:255',
        ]);

        return [
            'name'       => $request->branch_name,
            'code'       => $request->branch_code,
            'email'      => $request->branch_email,
            'phone'      => $request->branch_phone,
            'address'    => $request->branch_address,
            'is_default' => $request->boolean('is_default'),
        ];
    }

    // Ekta-i default branch thakbe; kono default na thakle prothom active branch default hobe
    private function keepSingleDefault(?Branch $branch)
    {
        if ($branch?->is_default) {
            Branch::where('id', '!=', $branch->id)->update(['is_default' => 0]);
        } elseif (!Branch::where('is_default', 1)->exists()) {
            Branch::where('status', 1)->orderBy('id')->limit(1)->update(['is_default' => 1]);
        }
    }
}
