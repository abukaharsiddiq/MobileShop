@extends('back-end.master')
@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header">
            <h6 class="m-0">IMEI Tracking Report</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('reports.imei') }}" method="GET" class="mb-4">
                <div class="row justify-content-center">
                    <div class="col-md-12">
                        <div class="input-group">
                            <input type="text" name="imei_no" class="form-control" placeholder="Enter IMEI Number..." value="{{ $imei_no }}" required>
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fa fa-search"></i> Search
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

           @if($imei_no)
    @if($result)
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <tr>
                            <th colspan="2" class="text-center">IMEI Device Lifecycle Info</th>
                        </tr>
                        <tr>
                            <th width="30%">Product Name:</th>
                            <td>{{ $result->product->product_name }}</td>
                        </tr>
                        <tr>
                            <th>Variant (RAM / ROM / Color):</th>
                            <td>
                                @if($result->mainStock)
                                    {{ implode(' / ', array_filter([$result->mainStock->ram->ram_name ?? null, $result->mainStock->rom->rom_name ?? null, $result->mainStock->color->color_name ?? null])) ?: 'N/A' }}
                                @else
                                    N/A
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>IMEI Number:</th>
                            <td><span class="badge badge-primary">{{ $result->imei_no }}</span></td>
                        </tr>
                        <tr>
                            <th>Current Status:</th>
                            <td>
                                {{-- আপনার ডাটাবেসে যদি 'sold' স্ট্রিং হিসেবে থাকে তবে এটি কাজ করবে --}}
                                @if($result->status == \App\Models\ProductImei::STATUS_SOLD)
                                    <span class="badge badge-danger">{{ $result->statusText() }}</span>
                                @elseif($result->status == \App\Models\ProductImei::STATUS_RETURNED_TO_SUPPLIER)
                                    <span class="badge badge-warning">{{ $result->statusText() }}</span>
                                @else
                                    <span class="badge badge-success">{{ $result->statusText() }}</span>
                                @endif
                            </td>
                        </tr>
                        
                        {{-- যদি বিক্রি হয়ে থাকে এবং sale_id থাকে --}}
                        @if($result->status == 0 && $result->sale)
                            <tr class="bg-light text-center font-weight-bold">
                                <td colspan="2">Linked Sale Details</td>
                            </tr>
                            <tr>
                                <th>Invoice Number:</th>
                                <td>
                                    <a href="{{ route('sale.show', $result->sale->id) }}" class="btn btn-sm btn-outline-info" target="_blank">
                                        <i class="fa fa-eye"></i> #{{ $result->sale->invoice_no }}
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <th>Customer Name:</th>
                                <td>{{ $result->sale->customer->name ?? 'N/A' }}</td>
                            </tr>
                            <tr>
                                <th>Contact Number:</th>
                                <td>{{ $result->sale->customer->phone ?? 'N/A' }}</td>
                            </tr>
                            <tr>
                                <th>Sale Date:</th>
                                <td>{{ \Carbon\Carbon::parse($result->sale->sale_date)->format('d M, Y') }}</td>
                            </tr>
                        @endif
                    </table>

                    {{-- ei IMEI er puro itihash (kena, bikri, ferot, registration ...) --}}
                    <table class="table table-bordered table-sm mt-3">
                        <tr>
                            <th colspan="6" class="text-center">IMEI History</th>
                        </tr>
                        <tr>
                            <th>Date</th><th>Event</th><th>Reference</th><th>Branch</th><th>User</th><th>Note</th>
                        </tr>
                        @forelse($history as $move)
                            <tr>
                                <td>{{ $move->created_at?->format('d M, Y h:i A') }}</td>
                                <td>{{ ucwords(str_replace('_', ' ', $move->event)) }}</td>
                                <td>{{ $move->reference_type }} {{ $move->reference_no ?? ($move->reference_id ? '#' . $move->reference_id : '') }}</td>
                                <td>{{ $move->branch->name ?? '-' }}</td>
                                <td>{{ $move->user->name ?? '-' }}</td>
                                <td>{{ $move->note }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">No history recorded yet (older records: run inventory:backfill-history).</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-warning text-center">
            No record found for IMEI: <strong>{{ $imei_no }}</strong>
        </div>
    @endif
@endif
        </div>
    </div>
</div>
@endsection