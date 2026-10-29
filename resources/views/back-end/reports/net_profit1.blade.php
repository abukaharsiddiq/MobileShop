@extends('back-end.master')
@section('admin-title')
  {{ $name }}
@endsection

@section('name')
{{ $name }}
@endsection

@section('title')
 {{ $title }}
@endsection
@section('admin-content')
<style>
    @media print {
        .no-print, .main-footer, .navbar, .sidebar, .btn, form {
            display: none !important;
        }
        .container-fluid {
            padding: 0 !important;
        }
        .card {
            border: none !important;
            shadow: none !important;
        }
        .card-body {
            padding: 0 !important;
        }
        body {
            background-color: white !important;
        }
        .table-bordered th, .table-bordered td {
            border: 1px solid #000 !important;
        }
    }
</style>

<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Profit & Loss Report (Net Profit)</h6>
            <button onclick="window.print()" class="btn back-btn no-print">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
        <div class="card-body">
            <div class="text-center d-none d-print-block mb-4">
                <p>Profit & Loss Report<br>
                Period: {{ date('d M, Y', strtotime($start_date)) }} to {{ date('d M, Y', strtotime($end_date)) }}</p>
            </div>

            <form action="" method="GET" class="mb-4 no-print">
                @include('back-end.reports._branch_select')
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
                <div class="col-md-3 col-6 mb-3">
                    <div class="card bg-info text-white shadow p-3">
                        <small>Total Sales (Revenue)</small>
                        <h4 class="mb-0 font-weight-bold"> {{ number_format($totalSales, 2) }}</h4>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="card bg-secondary text-white shadow p-3">
                        <small>Purchase Cost (COGS)</small>
                        <h4 class="mb-0 font-weight-bold"> {{ number_format($totalPurchaseCost, 2) }}</h4>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="card bg-danger text-white shadow p-3">
                        <small>Total Expenses</small>
                        <h4 class="mb-0 font-weight-bold"> {{ number_format($totalExpenses, 2) }}</h4>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="card {{ $netProfit >= 0 ? 'bg-success' : 'bg-dark' }} text-white shadow p-3">
                        <small>Net Profit / Loss</small>
                        <h4 class="mb-0 font-weight-bold"> {{ number_format($netProfit, 2) }}</h4>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <h5 class="text-dark font-weight-bold">Detail Summary Report</h5>
                <table class="table table-bordered w-100">
                    <thead>
                        <tr class="bg-light">
                            <th>Description</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Net Sales (A) <small class="text-muted">after discounts and returns, without tax</small></td>
                            <td class="text-right"> {{ number_format($totalSales, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Cost of Goods Sold (B)</td>
                            <td class="text-right text-danger">(-) {{ number_format($totalPurchaseCost, 2) }}</td>
                        </tr>
                        <tr class="font-weight-bold">
                            <td>Gross Profit (C = A - B)</td>
                            <td class="text-right"> {{ number_format($grossProfit, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Other Income (D)</td>
                            <td class="text-right">(+) {{ number_format($otherIncome ?? 0, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Total Expenses (E)</td>
                            <td class="text-right text-danger">(-) {{ number_format($totalExpenses, 2) }}</td>
                        </tr>
                        <tr class="{{ $netProfit >= 0 ? 'bg-success text-white' : 'bg-danger text-white' }} font-weight-bold" style="font-size: 1.1rem;">
                            <td>Net Profit (C + D - E)</td>
                            <td class="text-right"> {{ number_format($netProfit, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="d-none d-print-block mt-5">
                <div class="row">
                    <div class="col-4 text-center">
                        <hr style="border-top: 1px solid black;">
                        <p>Prepared By</p>
                    </div>
                    <div class="col-4"></div>
                    <div class="col-4 text-center">
                        <hr style="border-top: 1px solid black;">
                        <p>Authorized Signature</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection