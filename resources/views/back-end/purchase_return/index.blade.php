@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold">{{ $title }}</h6>
            <a href="{{ route('purchase-return.create') }}" class="btn back-btn">
                <i class="fas fa-plus"></i> New Return
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover zero_config" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>SL</th>
                            <th>Date</th>
                            <th>Return No</th>
                            <th>Supplier</th>
                            <th class="text-right">Total Amount</th>
                            <th>Note</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($returns as $key => $return)
                        <tr>
                            <td>{{ $key + 1 }}</td>
                            <td>{{ date('d-M-Y', strtotime($return->return_date)) }}</td>
                            <td class="font-weight-bold text-primary">{{ $return->return_no }}</td>
                            <td>{{ $return->supplier->name ?? 'N/A' }}</td>
                            <td class="text-right font-weight-bold">
                                {{ number_format($return->total_amount, 2) }}
                            </td>
                            <td>{{ $return->note ?? '-' }}</td>
                            <td class="text-center">
                                <div class="btn-group">
                                    {{-- ভিউ বাটন - এখানে ডিটেইলস দেখানোর ব্যবস্থা রাখতে পারেন --}}
                                    <a href="{{ route('purchase-return.show', $return->id) }}" class="btn btn-info btn-sm" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                   
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection