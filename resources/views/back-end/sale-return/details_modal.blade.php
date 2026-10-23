<div class="row border-bottom pb-2 mb-3">
    <div class="col-md-6">
        <p class="mb-1"><strong>Customer:</strong> {{ $return->customer->name ?? 'N/A' }}</p>
        <p class="mb-1"><strong>Invoice No:</strong> <span class="badge badge-info">{{ $return->sale->invoice_no ?? 'N/A' }}</span></p>
    </div>
    <div class="col-md-6 text-right">
        <p class="mb-1"><strong>Return Date:</strong> {{ \Carbon\Carbon::parse($return->return_date)->format('d M, Y') }}</p>
        <p class="mb-1"><strong>Total Refund:</strong> <span class="text-danger font-weight-bold">{{ number_format($return->total_refund, 2) }}</span></p>
    </div>
</div>

<h6 class="font-weight-bold">Returned Items List:</h6>
<table class="table table-sm table-bordered mt-2">
    <thead class="bg-light text-center">
        <tr>
            <th>Product Name</th>
            <th>IMEI Number</th>
            <th class="text-right">Price</th>
        </tr>
    </thead>
    <tbody>
        @foreach($return->returnItems as $item)
        <tr>
            <td>{{ $item->product->product_name ?? 'N/A' }}</td>
            <td class="text-center"><span class="badge badge-secondary">{{ $item->imei_no }}</span></td>
            <td class="text-right">{{ number_format($item->refund_price, 2) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

@if($return->note)
<div class="alert alert-warning mt-3">
    <strong>Reason/Note:</strong><br>
    {{ $return->note }}
</div>
@endif