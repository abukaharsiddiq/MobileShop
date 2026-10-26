@extends('back-end.master')

@section('admin-title', 'Installment Reminders')

@section('admin-content')
@php $money = fn ($v) => number_format((float) $v, 2); @endphp
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold">
              Reminders: overdue &amp; due until {{ date('d M Y', strtotime($until)) }}
              ({{ $installments->total() }})
            </h6>
            <a href="{{ route('installments.overdue') }}" class="btn btn-sm btn-outline-danger">Overdue Report</a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm" id="dueTable">
                    <thead>
                        <tr>
                            <th>#SL</th>
                            <th>Customer Info</th>
                            <th>Invoice</th>
                            <th>Inst. No</th>
                            <th class="text-right">Balance</th>
                            <th>Due Date</th>
                            <th>Days</th>
                            <th>Reminded</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($installments as $key => $row)
                        @php
                            $days = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($row->due_date), false);
                            $message = \App\Services\InstallmentService::reminderMessage($row);
                            $wa = \App\Services\InstallmentService::whatsappLink($row);
                        @endphp
                        <tr class="{{ $days < 0 ? 'table-danger' : '' }}" id="row{{ $row->id }}">
                            <td>{{ $installments->firstItem() + $key }}</td>
                            <td><strong>{{ $row->customer->name ?? '-' }}</strong><br><small>{{ $row->customer->phone ?? '' }}</small></td>
                            <td><a href="{{ route('installments.sale', $row->sale_id) }}">{{ $row->sale->invoice_no ?? '-' }}</a></td>
                            <td class="text-center">{{ $row->installment_no }}</td>
                            <td class="text-right font-weight-bold">{{ $money($row->openAmount()) }}</td>
                            <td>{{ date('d M Y', strtotime($row->due_date)) }}</td>
                            <td>
                                @if($days < 0)<span class="badge badge-danger">{{ abs($days) }} day(s) late</span>
                                @elseif($days === 0)<span class="badge badge-warning">Today</span>
                                @else<span class="badge badge-info">in {{ $days }} day(s)</span>@endif
                            </td>
                            <td class="reminded"><small>{{ $row->reminder_count ? $row->reminder_count . '× · ' . $row->last_reminded_at->format('d M h:i A') : 'Never' }}</small></td>
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="Copy message" onclick="copyMessage(this)" data-message="{{ $message }}"><i class="fa fa-copy"></i></button>
                                @if($row->customer && $row->customer->phone)
                                <a href="tel:{{ $row->customer->phone }}" class="btn btn-sm btn-outline-primary" title="Call" onclick="markReminded({{ $row->id }}, 'call')"><i class="fa fa-phone"></i></a>
                                @endif
                                @if($wa)
                                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-sm btn-success" title="WhatsApp" onclick="markReminded({{ $row->id }}, 'whatsapp')"><i class="fab fa-whatsapp"></i></a>
                                @endif
                                @can('create installment')
                                <a href="{{ route('installments.create', ['customer_id' => $row->customer_id]) }}" class="btn btn-sm btn-primary" title="Collect"><i class="fa fa-money-bill"></i></a>
                                @endcan
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="9" class="text-center text-muted">No installment is due soon.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $installments->links() }}
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    function copyMessage(btn) {
        let text = $(btn).data('message');
        navigator.clipboard ? navigator.clipboard.writeText(text) : window.prompt('Copy the message', text);
        toastr.success('Reminder message copied.');
        markReminded($(btn).closest('tr').attr('id').replace('row', ''), 'sms');
    }

    function markReminded(id, channel) {
        $.post(@json(url('installments')) + '/' + id + '/reminded', { _token: @json(csrf_token()), channel: channel })
            .done(() => $('#row' + id + ' .reminded small').text('Just now (' + channel + ')'));
    }
</script>
@endpush
