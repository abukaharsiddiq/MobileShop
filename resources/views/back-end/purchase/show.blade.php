@extends('back-end.master')

@section('admin-title', $title)

@section('admin-content')
@php $user = auth()->user(); @endphp
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center bg-white">
                <h3 class="card-title">
                    <strong>Purchase Invoice: #{{ $purchase->invoice_no }}</strong>
                    <span class="badge {{ $purchase->statusBadge() }} ml-2">{{ $purchase->statusLabel() }}</span>
                </h3>
                <div class="d-flex flex-wrap" style="gap: 6px;">
                    <a href="{{ route('purchase.index') }}" class="btn btn-secondary btn-sm"><i class="fa fa-list"></i> Back to List</a>
                    <a href="{{ route('purchase.print', $purchase->id) }}" target="_blank" class="btn btn-primary btn-sm"><i class="fa fa-print"></i> Print Invoice</a>

                    @if($purchase->isOpen())
                        @can('update purchase')
                            <a href="{{ route('purchase.edit', $purchase->id) }}" class="btn btn-info btn-sm"><i class="fa fa-edit"></i> Edit</a>
                        @endcan
                        @if($purchase->isDraft() && $user->canAny(['create purchase', 'update purchase']))
                            <form action="{{ route('purchase.status.change', $purchase->id) }}" method="POST">
                                @csrf <input type="hidden" name="action" value="submit">
                                <button class="btn btn-warning btn-sm"><i class="fa fa-paper-plane"></i> Submit for Approval</button>
                            </form>
                        @endif
                        @if($canApprove)
                            <form action="{{ route('purchase.status.change', $purchase->id) }}" method="POST" onsubmit="return confirm('Approve this purchase? Stock, IMEI and the supplier ledger will be updated.')">
                                @csrf <input type="hidden" name="action" value="approve">
                                <button class="btn btn-success btn-sm"><i class="fa fa-check"></i> Approve</button>
                            </form>
                        @endif
                    @endif

                    @if(!$purchase->isCancelled())
                        @can('delete purchase')
                            <form action="{{ route('purchase.status.change', $purchase->id) }}" method="POST" class="js-cancel-form">
                                @csrf <input type="hidden" name="action" value="cancel"><input type="hidden" name="reason">
                                <button class="btn btn-outline-danger btn-sm"><i class="fa fa-ban"></i> Cancel</button>
                            </form>
                        @endcan
                    @endif
                </div>
            </div>

            <div class="card-body">
                @if($purchase->isCancelled())
                    <div class="alert alert-dark">
                        Cancelled {{ $purchase->cancelled_at?->format('d M Y h:i A') }} by {{ $purchase->canceller?->name ?? '-' }}: {{ $purchase->cancel_reason }}
                        @if($purchase->approved_at) - stock, IMEI and the supplier ledger were reversed. @endif
                    </div>
                @elseif($purchase->isOpen())
                    <div class="alert alert-warning mb-3">
                        This purchase is <strong>{{ $purchase->statusLabel() }}</strong>. Stock, IMEI and the supplier ledger are not changed until it is approved.
                    </div>
                @endif

                <div class="row mb-4">
                    <div class="col-sm-6">
                        <h5 class="mb-3">Supplier Details:</h5>
                        <div><strong><a href="{{ route('supplier.show', $purchase->supplier_id) }}">{{ $purchase->supplier->name }}</a></strong></div>
                        <div>Address: {{ $purchase->supplier->address ?? 'N/A' }}</div>
                        <div>Phone: {{ $purchase->supplier->phone }}</div>
                    </div>
                    <div class="col-sm-6 text-sm-right">
                        <h5 class="mb-3">Purchase Info:</h5>
                        <div>Date: <strong>{{ date('d M, Y', strtotime($purchase->purchase_date)) }}</strong></div>
                        @if($purchase->supplier_invoice_no)<div>Supplier Bill No: <strong>{{ $purchase->supplier_invoice_no }}</strong></div>@endif
                        <div>Created by: {{ $purchase->creator?->name ?? '-' }}</div>
                        @if($purchase->approved_at)<div>Approved by: {{ $purchase->approver?->name ?? '-' }} ({{ $purchase->approved_at->format('d M Y h:i A') }})</div>@endif
                        @if($purchase->isApproved())
                        <div>Payment Status:
                            <span class="badge {{ $purchase->payment_status == 'Paid' ? 'badge-success' : ($purchase->payment_status == 'Partial' ? 'badge-warning' : 'badge-danger') }}">
                                {{ $purchase->payment_status }}
                            </span>
                        </div>
                        @endif
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
                                <th class="text-right">Unit Cost</th>
                                <th class="text-right">Discount</th>
                                <th class="text-right">Total</th>
                                <th class="text-right" title="Unit cost after discount, tax and additional cost">Landed / Unit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($purchase->purchaseDetails as $key => $detail)
                            @php $imeis = $detail->imeiTokens(); @endphp
                            <tr>
                                <td class="text-center">{{ $key + 1 }}</td>
                                <td>
                                    <strong>{{ $detail->product->product_name }}</strong>
                                    <br>
                                    @if($imeis)
                                        <button type="button" class="btn btn-xs btn-outline-info mt-1 view-imeis"
                                                data-product="{{ $detail->product->product_name }}"
                                                data-imeis="{{ implode(', ', $imeis) }}">
                                            <i class="fa fa-barcode"></i> View {{ count($imeis) }} IMEIs
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
                                <td class="text-right">{{ number_format($detail->landedUnitCost(), 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="row mt-4">
                    <div class="col-lg-5">
                        <h6>Note:</h6>
                        <p class="text-muted">{{ $purchase->note ?? 'No instructions provided.' }}</p>

                        @if($purchase->payments->isNotEmpty())
                        <h6 class="mt-3">Payment History</h6>
                        <table class="table table-sm table-bordered">
                            @foreach($purchase->payments as $payment)
                            <tr class="{{ $payment->isVoided() ? 'text-muted' : '' }}">
                                <td>{{ $payment->payment_date->format('d-m-Y') }}</td>
                                <td>{{ $payment->payment_no }}<br><small>{{ $payment->payment_method }}{{ $payment->reference_no ? ' · ' . $payment->reference_no : '' }}</small></td>
                                <td class="text-right">
                                    @if($payment->isVoided())<del>{{ number_format($payment->amount, 2) }}</del> <small class="text-danger">void</small>
                                    @else{{ number_format($payment->amount, 2) }}@endif
                                </td>
                            </tr>
                            @endforeach
                        </table>
                        @endif

                        @if($purchase->returns->isNotEmpty())
                        <h6 class="mt-3">Returns</h6>
                        <table class="table table-sm table-bordered">
                            @foreach($purchase->returns as $return)
                            <tr>
                                <td>{{ date('d-m-Y', strtotime($return->return_date)) }}</td>
                                <td><a href="{{ route('purchase-return.show', $return->id) }}">{{ $return->return_no }}</a></td>
                                <td class="text-right">{{ number_format($return->total_amount, 2) }}</td>
                            </tr>
                            @endforeach
                        </table>
                        @endif
                    </div>
                    <div class="col-lg-4 col-sm-6 ml-auto">
                        <table class="table table-clear">
                            <tbody>
                                <tr><td><strong>Subtotal</strong></td><td class="text-right">{{ number_format($purchase->subtotal, 2) }}</td></tr>
                                <tr><td><strong>Overall Discount</strong></td><td class="text-right">- {{ number_format($purchase->discount, 2) }}</td></tr>
                                @if($purchase->tax_amount > 0)
                                <tr><td><strong>Tax / VAT ({{ (float) $purchase->tax_rate }}%)</strong></td><td class="text-right">{{ number_format($purchase->tax_amount, 2) }}</td></tr>
                                @endif
                                @if($purchase->additional_cost > 0)
                                <tr><td><strong>Additional Cost</strong>@if($purchase->additional_cost_note)<br><small>{{ $purchase->additional_cost_note }}</small>@endif</td><td class="text-right">{{ number_format($purchase->additional_cost, 2) }}</td></tr>
                                @endif
                                <tr><td><strong>Grand Total</strong></td><td class="text-right"><strong>{{ number_format($purchase->grand_total, 2) }}</strong></td></tr>
                                @if($purchase->isApproved())
                                    <tr><td><strong>Paid Amount</strong></td><td class="text-right text-success">{{ number_format($purchase->paid_amount, 2) }}</td></tr>
                                    @if($purchase->return_amount > 0)
                                    <tr><td><strong>Returned</strong></td><td class="text-right">{{ number_format($purchase->return_amount, 2) }}</td></tr>
                                    @endif
                                    <tr><td><strong class="text-danger">Due Amount</strong></td><td class="text-right"><strong class="text-danger">{{ number_format($purchase->due_amount, 2) }}</strong></td></tr>
                                @elseif($purchase->isOpen())
                                    <tr><td><strong>To pay on approval</strong></td><td class="text-right">{{ number_format($purchase->initial_payment, 2) }} ({{ $purchase->payment_method }})</td></tr>
                                @endif
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
                <div id="imeiListContainer" class="d-flex flex-wrap" style="gap: 5px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
$(document).ready(function() {
    $('.view-imeis').on('click', function() {
        $('#modalProductName').text('IMEIs for: ' + $(this).data('product'));
        let container = $('#imeiListContainer').empty();
        // text hisebe (HTML na)
        String($(this).data('imeis')).split(', ').forEach(function(imei) {
            container.append($('<span class="badge badge-light border p-2" style="font-size: 13px; min-width: 140px;">').text(imei));
        });
        $('#viewImeiModal').modal('show');
    });

    $('.js-cancel-form').on('submit', function(e) {
        const reason = prompt('Why are you cancelling this purchase?');
        if (!reason || !reason.trim()) { e.preventDefault(); return; }
        $(this).find('input[name=reason]').val(reason.trim());
    });
});
</script>
@endpush
