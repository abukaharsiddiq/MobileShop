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
    /* প্রিন্ট করার জন্য বিশেষ সিএসএস */
    @media print {
        .d-print-none, .main-footer, .navbar, .sidebar, .btn, .card-header:first-child, .form-control {
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
    <div class="card d-print-none mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="card-title m-0">Search & Filter</h4>
            <button onclick="window.print()" class="btn btn-sm btn-secondary">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
        <div class="card-body">
            <form action="" method="GET">
                @include('back-end.reports._branch_select')
                <div class="row align-items-end">
                    <div class="col-md-5">
                        <label class="small font-weight-bold">From Date</label>
                        <input type="date" name="start_date" class="form-control" value="{{ $start_date }}">
                    </div>
                    <div class="col-md-5">
                        <label class="small font-weight-bold">To Date</label>
                        <input type="date" name="end_date" class="form-control" value="{{ $end_date }}">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-block">Filter Records</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="text-center d-none d-print-block mt-4 mb-4">
            <h5 class="mb-0">Expense Report</h5>
            <p>Period: {{ \Carbon\Carbon::parse($start_date)->format('d M, Y') }} - {{ \Carbon\Carbon::parse($end_date)->format('d M, Y') }}</p>
        </div>

        <div class="card-header d-print-none">
            <h6 class="m-0 font-weight-bold">
                Expense Report ({{ \Carbon\Carbon::parse($start_date)->format('d M') }} - {{ \Carbon\Carbon::parse($end_date)->format('d M, Y') }})
            </h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" width="100%" cellspacing="0">
                    <thead class="bg-light">
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Reference/Note</th>
                            <th>Payment Method</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($expenses as $expense)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($expense->expense_date)->format('d-m-Y') }}</td>
                            <td>{{ $expense->category->name }}</td>
                            <td><small>{{ $expense->note ?? 'No description' }}</small></td>
                            <td>{{ $expense->payment_method }}</td>
                            <td class="text-right font-weight-bold">{{ number_format($expense->amount, 2) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No records found for this date range.</td>
                        </tr>
                        @endforelse
                    </tbody>
                    <tfoot class="bg-light">
                        <tr>
                            <th colspan="4" class="text-right font-weight-bold">Total Amount:</th>
                            <th class="text-right text-danger font-weight-bold" style="font-size: 1.1rem;">{{ number_format($totalAmount, 2) }}</th>
                        </tr>
                    </tfoot>
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