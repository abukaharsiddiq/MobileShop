@extends('back-end.master')
@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="m-0 font-weight-bold text-primary">Profit & Loss Report (Net Profit)</h6>
        </div>
        <div class="card-body">
            <form action="" method="GET" class="mb-4">
                <div class="row">
                    <div class="col-md-4">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="{{ $start_date }}">
                    </div>
                    <div class="col-md-4">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" value="{{ $end_date }}">
                    </div>
                    <div class="col-md-2">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary btn-block">Generate Report</button>
                    </div>
                </div>
            </form>

            <div class="row">
                <div class="col-md-3">
                    <div class="card bg-info text-white shadow p-3">
                        <small>Total Sales (Revenue)</small>
                        <h4 class="mb-0 font-weight-bold"> {{ number_format($totalSales, 2) }}</h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-secondary text-white shadow p-3">
                        <small>Purchase Cost (COGS)</small>
                        <h4 class="mb-0 font-weight-bold"> {{ number_format($totalPurchaseCost, 2) }}</h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white shadow p-3">
                        <small>Total Expenses</small>
                        <h4 class="mb-0 font-weight-bold"> {{ number_format($totalExpenses, 2) }}</h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card {{ $netProfit >= 0 ? 'bg-success' : 'bg-dark' }} text-white shadow p-3">
                        <small>Net Profit / Loss</small>
                        <h4 class="mb-0 font-weight-bold"> {{ number_format($netProfit, 2) }}</h4>
                    </div>
                </div>
            </div>

            <div class="mt-5">
                <h5 class="text-dark font-weight-bold">Detail Summary Report</h5>
                <table class="table table-bordered col-md-12">
                    <tr>
                        <td>Total Sales (A)</td>
                        <td class="text-right"> {{ number_format($totalSales, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Cost of Goods Sold (B)</td>
                        <td class="text-right">(-)  {{ number_format($totalPurchaseCost, 2) }}</td>
                    </tr>
                    <tr class="bg-light font-weight-bold">
                        <td>Gross Profit (C = A - B)</td>
                        <td class="text-right"> {{ number_format($grossProfit, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Total Expenses (D)</td>
                        <td class="text-right">(-)  {{ number_format($totalExpenses, 2) }}</td>
                    </tr>
                    <tr class="{{ $netProfit >= 0 ? 'text-success' : 'text-danger' }} font-weight-bold" style="font-size: 1.2rem;">
                        <td>Net Profit (C - D)</td>
                        <td class="text-right"> {{ number_format($netProfit, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection