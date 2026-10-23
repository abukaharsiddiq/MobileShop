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

@push('admin-styles')
<style>
    .marginTop{
        margin-top:33px;
    }
</style>
@endpush

@section('admin-content')
<div class="row">
    <div class="col-md-12">

        <div class="card">
    <div class="card-header">
        <h5 class="card-title">Dead Stock</h5>
    </div>
    <div class="card-body">
        <table class="table table-hover table-bordered zero_config">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Date</th>
                    <th>In Days</th>
                    <!-- <th>Action</th> -->
                </tr>
            </thead>
            <tbody>
                @foreach($deadStocks as $item)
                <tr>
                    <td>{{ $item->product->product_name }}</td>
                    <td>
                        {{ \Carbon\Carbon::parse($item->created_at)->format('d M, Y') }}
                    </td>
                    <td>
                        <span class="text-danger">
                               {{ number_format(\Carbon\Carbon::parse($item->created_at)->diffInDays(now())) }}
                            দিন আগে কেনা
                        </span>
                    </td>
                   <!--  <td>
                        <button class="btn btn-warning btn-sm">অফার দিন (Discount)</button>
                    </td> -->
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

    </div>
</div>

<style>
    @media print {
        .no-print, .main-footer, .sidebar, .navbar {
            display: none !important;
        }
        .content-wrapper { margin-left: 0 !important; }
    }
</style>
@endsection

@push('admin-scripts')

@endpush