@extends('back-end.master')

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card mb-3 no-print">
            <div class="card-body">
                <form action="" method="GET" class="row align-items-end">
                    @include('back-end.reports._branch_select', ['wrap' => 'col-md-3 form-group'])
                    <div class="col-md-4">
                        <label class="font-weight-bold">কত দিনের পুরনো স্টক দেখবেন? (Days)</label>
                        <input type="number" name="days" class="form-control" value="{{ $days }}" placeholder="e.g. 90">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fa fa-filter"></i> Filter
                        </button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" onclick="window.print()" class="btn btn-success btn-block">
                            <i class="fa fa-print"></i> Print
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white">
                <h5 class="card-title m-0 text-danger font-weight-bold">
                    Dead Stock Report ({{ $days }}+ Days Old)
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered zero_config">
                        <thead class="bg-light">
                            <tr>
                                <th>Product Name</th>
                                <th>Purchase Date</th>
                                <th class="text-center">Stock Duration</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($deadStocks as $item)
                            <tr>
                                <td>
                                    <span class="font-weight-bold">{{ $item->product->product_name }}</span><br>
                                    <small class="text-muted">IMEI: {{ $item->imei_no }}</small>
                                </td>
                                <td>{{ \Carbon\Carbon::parse($item->created_at)->format('d M, Y') }}</td>
                                <td class="text-center">
                                    @php
                                        $diff = number_format(\Carbon\Carbon::parse($item->created_at)->diffInDays(now()));
                                    @endphp
                                    <span class="{{ $diff > 90 ? 'text-danger' : 'text-danger' }}" style="font-size: 14px; padding: 5px 10px;">
                                        {{ $diff }} দিন যাবত স্টকে
                                    </span>
                                </td>
                                <td class="text-right no-print">
                                    <a href="#" class="btn btn-outline-primary btn-sm">অফার দিন</a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="text-center">কোন ডেড স্টক পাওয়া যায়নি।</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .no-print { display: none !important; }
        .card { border: none !important; }
        .table-bordered th, .table-bordered td { border: 1px solid #000 !important; }
    }
</style>
@endsection