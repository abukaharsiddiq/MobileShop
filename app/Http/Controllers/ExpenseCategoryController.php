<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;

class ExpenseCategoryController extends Controller
{
    private $heading;
    public function __construct()
    {
        $this->heading = 'Expense Category';
    }

    public function index(){
        $name = $this->heading;
        $title = $this->heading;
        $categories = ExpenseCategory::latest()->get();
        return view('back-end.expense.category.index', compact('name', 'title', 'categories'));
    }

    public function create(){
        $name = $this->heading;
        $title = $this->heading;
        return view('back-end.expense.category.create', compact('name', 'title'));
    }

public function store(Request $request){

    $request->validate([
        'name.*' => 'required|distinct|unique:expense_categories,name',
        'status.*' => 'required|in:0,1',
    ]);

    foreach ($request->name as $index => $name) {
        ExpenseCategory::create([
            'name'   => $name,
            'status' => $request->status[$index] ?? 1,
        ]);
    }

    Toastr::success('Data Added Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('expense-category.index');
}


    public function edit($id){
        $name = $this->heading;
        $title = $this->heading;
        $category = ExpenseCategory::findOrFail($id);
        return view('back-end.expense.category.edit', compact('category', 'title', 'name'));
    }

public function update(Request $request, $id){

    $request->validate([
        'name.*'   => 'required|distinct|unique:expense_categories,name,'.$id,
        'status.*' => 'required|in:0,1',
    ]);

    // Find the main category
    $category = ExpenseCategory::findOrFail($id);

    // Update the first row as main category
    $category->name   = $request->name[0];
    $category->status = $request->status[0] ?? 1;
    $category->save();

   
    if(count($request->name) > 1) {
        for($i = 1; $i < count($request->name); $i++) {
            ExpenseCategory::create([
                'name'   => $request->name[$i],
                'status' => $request->status[$i] ?? 1,
            ]);
        }
    }

    Toastr::success('Data Updated Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('expense-category.index');
}

 public function changeStatus($id, Request $request) {
        $model = ExpenseCategory::find($id);
        return changeStatusHelper($model,$request);
    }


    public function delete($id){
        $category = ExpenseCategory::findOrFail($id);
        $category->delete();

        Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('expense-category.index');
    }

}
