<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    public function index()
    {
        $expenses = Expense::with('category')->orderBy('expense_date', 'desc')->get();
        return view('back-end.expense.expense.index', compact('expenses'));
    }

    public function create()
    {
        $categories = ExpenseCategory::all();
        return view('back-end.expense.expense.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required',
            'expense_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required'
        ]);

        Expense::create([
            'category_id' => $request->category_id,
            'expense_date' => $request->expense_date,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'note' => $request->note,
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('expense.index')->with('success', 'Expense recorded successfully!');
    }

    public function edit($id)
    {
        $expense = Expense::findOrFail($id);
        $categories = ExpenseCategory::all();
        return view('back-end.expense.expense.edit', compact('expense', 'categories'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'category_id' => 'required',
            'expense_date' => 'required|date',
            'amount' => 'required|numeric',
        ]);

        $expense = Expense::findOrFail($id);
        $expense->update([
            'category_id' => $request->category_id,
            'expense_date' => $request->expense_date,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'note' => $request->note,
        ]);

        return redirect()->route('expense.index')->with('success', 'Expense updated successfully!');
    }

    public function destroy($id)
    {
        Expense::findOrFail($id)->delete();
        return redirect()->back()->with('success', 'Expense deleted successfully!');
    }
}
