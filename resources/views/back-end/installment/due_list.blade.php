@extends('back-end.master')

@section('admin-title', 'Due Installment List')

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-2">
            <h6 class="m-0 font-weight-bold">
              Upcoming & Overdue Installments
              ({{ $installments->total() }}) Pending
            </h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="dueTable">
                    <thead>
                        <tr>
                            <th>#SL</th>
                            <th>Customer Info</th>
                            <th>Invoice</th>
                            <th>Inst. No</th>
                            <th>Payable</th>
                            <th>Due Date</th>
                            <th>Days Left</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($installments as $key => $row)
                        @php
                            $dueDate = \Carbon\Carbon::parse($row->due_date);
                            $isOverdue = $dueDate->isPast() && !$dueDate->isToday();
                            $daysDiff = \Carbon\Carbon::now()->diffInDays($dueDate, false);
                        @endphp
                        <tr class="{{ $isOverdue ? 'table-danger' : '' }}">
                            <td>{{ $installments->firstItem() + $key }}</td>
                            <td>
                                <strong>{{ $row->customer_name }}</strong><br>
                                <small class="text-muted"><i class="fa fa-phone"></i> {{ $row->phone }}</small>
                            </td>
                            <td><span class="badge badge-secondary">{{ $row->invoice_no }}</span></td>
                            <td class="text-center">{{ $row->installment_no }}</td>
                            <td class="text-right font-weight-bold">{{ number_format($row->amount, 2) }}</td>
                            <td>
                                <span class="{{ $isOverdue ? 'text-danger font-weight-bold' : '' }}">
                                    {{ $dueDate->format('d M, Y') }}
                                </span>
                            </td>
                            <td>
                                @if($isOverdue)
                                    <span class="badge badge-danger">Overdue by {{ abs($daysDiff) }} days</span>
                                @elseif($dueDate->isToday())
                                    <span class="badge badge-warning">Due Today</span>
                                @else
                                    <span class="badge badge-info">{{ $daysDiff }} days left</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <a href="{{ route('installments.create', ['customer_id' => $row->customer_id]) }}" 
                                   class="btn btn-success btn-sm shadow-sm">
                                    <i class="fa fa-hand-holding-usd"></i> Collect
                                </a>
                                <a href="tel:{{ $row->phone }}" class="btn btn-primary btn-sm shadow-sm" title="Call Customer">
                                    <i class="fa fa-phone"></i>
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted">Great! No pending installments found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            <div class="mt-3 d-flex justify-content-end">
                {{ $installments->links() }}
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(document).ready(function() {
        $('#dueTable').DataTable({
            "pageLength": 25,
            "ordering": false
        });
    });
</script>
@endpush