@extends('back-end.master')

@section('admin-title', $title)

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center bg-white">
                <h3 class="card-title"><strong>Purchase Invoice: #{{ $purchase->invoice_no }}</strong></h3>
                <div>
                    <a href="{{ route('purchase.index') }}" class="btn btn-secondary btn-sm">
                        <i class="fa fa-list"></i> Back to List
                    </a>
                    <button onclick="window.print()" class="btn btn-primary btn-sm">
                        <i class="fa fa-print"></i> Print Invoice
                    </button>
                </div>
            </div>

            <div class="card-body" id="printableArea">
                <div class="row mb-4">
                    <div class="col-sm-6">
                        <h5 class="mb-3">Supplier Details:</h5>
                        <div><strong>{{ $purchase->supplier->name }}</strong></div>
                        <div>Address: {{ $purchase->supplier->address ?? 'N/A' }}</div>
                        <div>Phone: {{ $purchase->supplier->phone }}</div>
                    </div>
                    <div class="col-sm-6 text-sm-right">
                        <h5 class="mb-3">Purchase Info:</h5>
                        <div>Date: <strong>{{ date('d M, Y', strtotime($purchase->purchase_date)) }}</strong></div>
                        <div>Payment Status: 
                            <span class="badge {{ $purchase->payment_status == 'Paid' ? 'badge-success' : ($purchase->payment_status == 'Partial' ? 'badge-warning' : 'badge-danger') }}">
                                {{ $purchase->payment_status }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="table-responsive-sm">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th class="text-center">#</th>
                                <th>Product Name</th>
                                <th>Variant (R/R/C)</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Discount</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($purchase->purchaseDetails as $key => $detail)
                            <tr>
                                <td class="text-center">{{ $key + 1 }}</td>
                                <td>
    <strong>{{ $detail->product->product_name }}</strong>
    <br>
    @if($detail->product_imeis->count() > 0)
        <button type="button" class="btn btn-xs btn-outline-info mt-1 view-imeis" 
                data-product="{{ $detail->product->product_name }}"
                data-imeis="{{ $detail->product_imeis->pluck('imei_no')->implode(', ') }}">
            <i class="fa fa-barcode"></i> View {{ $detail->product_imeis->count() }} IMEIs
        </button>
    @else
        <small class="text-muted">No IMEI recorded</small>
    @endif
</td>
                                <td>
                                    R: {{ $detail->ram->ram_name ?? 'N/A' }} | 
                                    M: {{ $detail->rom->rom_name ?? 'N/A' }} | 
                                    C: {{ $detail->color->color_name ?? 'N/A' }}
                                </td>
                                <td class="text-center">{{ $detail->quantity }}</td>
                                <td class="text-right">{{ number_format($detail->purchase_price, 2) }}</td>
                                <td class="text-right">{{ number_format($detail->unit_discount, 2) }}</td>
                                <td class="text-right">{{ number_format($detail->unit_total, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="row mt-4">
                    <div class="col-lg-4 col-sm-5">
                        <h6>Note:</h6>
                        <p class="text-muted">{{ $purchase->note ?? 'No instructions provided.' }}</p>
                    </div>
                    <div class="col-lg-4 col-sm-5 ml-auto">
                        <table class="table table-clear">
                            <tbody>
                                <tr>
                                    <td class="left"><strong>Subtotal</strong></td>
                                    <td class="text-right">{{ number_format($purchase->subtotal, 2) }}</td>
                                </tr>
                                <tr>
                                    <td class="left"><strong>Overall Discount</strong></td>
                                    <td class="text-right">{{ number_format($purchase->discount, 2) }}</td>
                                </tr>
                                <tr>
                                    <td class="left"><strong>Grand Total</strong></td>
                                    <td class="text-right"><strong>{{ number_format($purchase->grand_total, 2) }}</strong></td>
                                </tr>
                                <tr>
                                    <td class="left"><strong>Paid Amount</strong></td>
                                    <td class="text-right text-success">{{ number_format($purchase->paid_amount, 2) }}</td>
                                </tr>
                                <tr>
                                    <td class="left"><strong class="text-danger">Due Amount</strong></td>
                                    <td class="text-right"><strong class="text-danger">{{ number_format($purchase->due_amount, 2) }}</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="viewImeiModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="modalProductName">Product IMEIs</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="imeiListContainer" class="d-flex flex-wrap" style="gap: 5px;">
                    </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .btn, .main-footer, .sidebar-wrapper, .navbar {
            display: none !important;
        }
        .card {
            border: none !important;
        }
    }
</style>
@endsection

@push('admin-scripts')
<script>
$(document).ready(function() {
    $('.view-imeis').on('click', function() {
        let productName = $(this).data('product');
        let imeis = $(this).data('imeis').split(', '); // কমা দিয়ে আলাদা করা
        
        $('#modalProductName').text('IMEIs for: ' + productName);
        
        let html = '';
        imeis.forEach(function(imei, index) {
            html += `<span class="badge badge-light border p-2" style="font-size: 13px; min-width: 140px;">
                        ${imei}
                     </span>`;
        });
        
        $('#imeiListContainer').html(html);
        $('#viewImeiModal').modal('show');
    });
});
</script>
@endpush


