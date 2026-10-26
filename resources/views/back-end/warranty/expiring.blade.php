@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Warranties ending in the next {{ $days }} days</h5>
            <form method="GET" class="form-inline">
                <input type="number" name="days" min="1" max="365" class="form-control form-control-sm mr-2" value="{{ $days }}" style="width:90px">
                <button class="btn btn-sm btn-primary">Show</button>
            </form>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover table-sm">
                <thead class="thead-light"><tr><th>Ends</th><th>Days</th><th>Warranty</th><th>Customer</th><th>Product / IMEI</th><th>Invoice</th><th>Reminded</th><th></th></tr></thead>
                <tbody>
                    @forelse($warranties as $w)
                        <tr>
                            <td>{{ $w->end_date->format('d-m-Y') }}</td>
                            <td>{{ $w->daysLeft() }}</td>
                            <td><a href="{{ route('warranty.show', $w->id) }}">{{ $w->warranty_no }}</a> <small>{{ $w->type }}</small></td>
                            <td>{{ $w->customer->name ?? '' }}<br><small>{{ $w->customer->phone ?? '' }}</small></td>
                            <td>{{ $w->product->product_name ?? '' }}<br><small>{{ $w->imei_no }}</small></td>
                            <td>{{ $w->sale->invoice_no ?? '' }}</td>
                            <td>{{ $w->last_reminded_at ? $w->last_reminded_at->format('d-m-Y') . ' (' . $w->reminder_count . ')' : '-' }}</td>
                            <td class="text-nowrap">
                                @if($link = \App\Services\WarrantyService::whatsappLink($w))
                                    <a href="{{ $link }}" target="_blank" class="btn btn-sm btn-success js-remind" data-url="{{ route('warranty.reminded', $w->id) }}"><i class="fab fa-whatsapp"></i></a>
                                @endif
                                <form method="POST" action="{{ route('warranty.reminded', $w->id) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="channel" value="call">
                                    <button class="btn btn-sm btn-outline-secondary" title="Mark as called"><i class="fa fa-phone"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No warranty is ending in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $('.js-remind').on('click', function () {
        $.post($(this).data('url'), {channel: 'whatsapp', _token: '{{ csrf_token() }}'});
    });
</script>
@endpush
