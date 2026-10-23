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
    /* প্রিন্ট লেআউট ঠিক করার জন্য CSS */
    @media print {
        .d-print-none, .main-footer, .navbar, .sidebar, .btn, form {
            display: none !important;
        }
        .container-fluid {
            padding: 0 !important;
        }
        .card {
            border: none !important;
            box-shadow: none !important;
        }
        .table-bordered th, .table-bordered td {
            border: 1px solid #000 !important;
            color: #000 !important;
        }
        body {
            background-color: white !important;
        }
    }
</style>

<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary d-print-none">Category-wise Expense Report</h6>
            <button onclick="window.print()" class="btn btn-sm btn-secondary d-print-none">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
        
        <div class="card-body">
            <div class="text-center d-none d-print-block mb-4">
                <h5 class="mb-1">Category-wise Expense Report</h5>
                <p>Period: {{ date('d M, Y', strtotime($start_date)) }} - {{ date('d M, Y', strtotime($end_date)) }}</p>
            </div>

            <form action="" method="GET" class="mb-4 d-print-none">
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
                        <button type="submit" class="btn btn-primary btn-block">Filter</button>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th class="text-right">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reportData as $data)
                        <tr>
                            <td class="font-weight-bold">{{ $data->category->name }}</td>
                            <td class="text-right">{{ number_format($data->total_amount, 2) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="2" class="text-center">No data found for the selected period.</td>
                        </tr>
                        @endforelse
                    </tbody>
                    <tfoot class="bg-light text-dark font-weight-bold">
                        <tr>
                            <td>Total Expense</td>
                            <td class="text-right text-danger" style="font-size: 1.1rem;">
                                {{ number_format($totalExpense, 2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="d-none d-print-block mt-5 pt-4">
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