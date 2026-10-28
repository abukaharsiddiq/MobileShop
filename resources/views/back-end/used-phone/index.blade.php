@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        @foreach([
            ['Waiting for approval', $summary['pending'], null, 'warning'],
            ['In refurbishment', $summary['refurbishing'], null, 'info'],
            ['In stock', $summary['in_stock'], 'Cost ' . number_format($summary['stock_cost'], 2) . ' · expected margin ' . number_format($summary['expected_margin'], 2), 'primary'],
            ['Sold', $summary['sold'], 'Sales ' . number_format($summary['sold_value'], 2) . ' · margin ' . number_format($summary['realized_margin'], 2), 'success'],
        ] as [$label, $count, $sub, $color])
            <div class="col-md-3 col-6">
                <div class="card card-outline card-{{ $color }}">
                    <div class="card-body py-2">
                        <div class="text-muted small">{{ $label }}</div>
                        <div class="h4 m-0">{{ $count }}</div>
                        @if($sub)<div class="small">{{ $sub }}</div>@endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Used & Refurbished Phones</h5>
            @can('create used phone')
                <a href="{{ route('used-phone.create') }}" class="btn btn-sm btn-primary"><i class="fa fa-plus"></i> Buy used phone</a>
            @endcan
        </div>
        <div class="card-body">
            <form method="GET" class="form-row mb-2">
                <div class="col-md-3 col-12 mb-2">
                    <input type="text" name="q" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="Intake no, IMEI (or last digits), seller phone">
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <select name="status" class="form-control form-control-sm">
                        <option value="">Any status</option>
                        @foreach(\App\Http\Controllers\UsedPhoneController::FILTERS as $key => $label)
                            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 col-6 mb-2"><input type="date" name="from_date" class="form-control form-control-sm" value="{{ request('from_date') }}" title="Entered from"></div>
                <div class="col-md-2 col-6 mb-2"><input type="date" name="to_date" class="form-control form-control-sm" value="{{ request('to_date') }}" title="Entered to"></div>
                <div class="col-md-3 col-6 mb-2"><button class="btn btn-sm btn-primary">Filter</button></div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="thead-light">
                        <tr><th>Intake</th><th>Date</th><th>Model / IMEI</th><th>Seller</th><th>Grade</th><th>Battery</th>
                            <th class="text-right">Cost</th><th class="text-right">Resale</th><th class="text-right">Margin</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse($phones as $phone)
                            @php $margin = $phone->realizedMargin() ?? $phone->expectedMargin(); @endphp
                            <tr>
                                <td><a href="{{ route('used-phone.show', $phone->id) }}">{{ $phone->intake_no }}</a></td>
                                <td>{{ $phone->created_at->format('d-m-Y') }}</td>
                                <td>{{ $phone->mainStock?->variantLabel() ?? $phone->product->product_name }}<br><small>{{ $phone->numbersLabel() }}</small></td>
                                <td>{{ $phone->seller_name }}<br><small class="text-muted">{{ $phone->sourceLabel() }}</small></td>
                                <td>{{ $phone->condition_grade }}</td>
                                <td class="{{ $phone->batteryIsLow() ? 'text-danger' : '' }}">{{ $phone->battery_health !== null ? $phone->battery_health . '%' : '-' }}</td>
                                <td class="text-right">{{ number_format($phone->totalCost(), 2) }}</td>
                                <td class="text-right">{{ $phone->soldPrice() !== null ? number_format($phone->soldPrice(), 2) : ($phone->resale_price !== null ? number_format($phone->resale_price, 2) : '-') }}</td>
                                <td class="text-right {{ $margin !== null && $margin < 0 ? 'text-danger' : '' }}">{{ $margin !== null ? number_format($margin, 2) : '-' }}</td>
                                <td><span class="badge badge-{{ $phone->resaleBadge() }}">{{ $phone->resaleStatus() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted">No used phones.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $phones->links() }}
        </div>
    </div>
</div>
@endsection
