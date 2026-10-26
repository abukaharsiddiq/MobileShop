@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $type = old('type', 'payment'); @endphp
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h1 class="card-title mb-0">Payment - {{ $supplier->name }}</h1>
                    <a href="{{ route('supplier.show', $supplier->id) }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Profile</a>
                </div>
                <form action="{{ route('supplier.payment.store', $supplier->id) }}" method="POST" id="supplierPaymentForm">
                    @csrf
                    {{-- ekoi form duibar submit hole server ekbar e hishab kore --}}
                    <input type="hidden" name="payment_token" value="{{ old('payment_token', $token) }}">
                    <div class="card-body">

                        <div class="form-group">
                            <label class="mr-3"><input type="radio" name="type" value="payment" @checked($type === 'payment')> Pay supplier</label>
                            <label class="{{ $summary['advance'] > 0 ? '' : 'text-muted' }}">
                                <input type="radio" name="type" value="refund" @checked($type === 'refund') @disabled($summary['advance'] <= 0)>
                                Supplier refunded our advance
                            </label>
                        </div>

                        <div class="form-group js-payment-only">
                            <label>Apply to</label>
                            <select name="purchase_id" id="purchase_id" class="form-control">
                                <option value="">On account - oldest dues first</option>
                                @foreach($openInvoices as $invoice)
                                    <option value="{{ $invoice->id }}" data-due="{{ $invoice->due_amount }}" @selected((int) old('purchase_id', $selectedPurchase) === $invoice->id)>
                                        {{ $invoice->invoice_no }} ({{ date('d-m-Y', strtotime($invoice->purchase_date)) }}) - due {{ number_format($invoice->due_amount, 2) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Amount <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" value="{{ old('amount') }}" required>
                                @error('amount')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>
                            <div class="form-group col-md-6">
                                <label>Date <span class="text-danger">*</span></label>
                                <input type="date" name="payment_date" class="form-control" value="{{ old('payment_date', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}" required>
                                @error('payment_date')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Method <span class="text-danger">*</span></label>
                                <select name="payment_method" class="form-control" required>
                                    @foreach($methods as $method)
                                        <option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ $method === 'Bank' ? 'Bank Transfer' : $method }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-4">
                                <label>Account</label>
                                <input type="text" name="payment_account" class="form-control" value="{{ old('payment_account') }}" maxlength="255" placeholder="Bank / wallet number">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Reference (Cheque / Trx ID)</label>
                                <input type="text" name="reference_no" class="form-control" value="{{ old('reference_no') }}" maxlength="100">
                                <small class="text-muted">The same reference cannot be recorded twice.</small>
                            </div>
                        </div>

                        <div class="form-group js-payment-only">
                            <label>
                                <input type="checkbox" name="allow_advance" value="1" @checked(old('allow_advance'))>
                                Allow advance: if the amount is more than the current due, keep the extra as an advance with the supplier
                            </label>
                        </div>

                        <div class="form-group">
                            <label>Note</label>
                            <textarea name="note" class="form-control" rows="2" maxlength="500">{{ old('note') }}</textarea>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Balance</h5></div>
                <div class="card-body">
                    <p class="mb-1">Current due: <strong class="text-danger">{{ number_format($summary['due'], 2) }}</strong></p>
                    <p class="mb-1">Advance with supplier: <strong class="text-success">{{ number_format($summary['advance'], 2) }}</strong></p>
                    <p class="mb-0">Open invoices: {{ $summary['open_invoices'] }}</p>
                    <hr>
                    <small class="text-muted">
                        An on-account payment settles the opening balance and the oldest invoices first.
                        A recorded payment cannot be edited; it can be voided from the payment history.
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(function () {
        const due = {{ $summary['due'] }};
        const advance = {{ $summary['advance'] }};

        function refresh() {
            const refund = $('input[name=type]:checked').val() === 'refund';
            $('.js-payment-only').toggle(!refund);
            if (refund) {
                $('#amount').attr('max', advance);
                return;
            }
            const invoiceDue = $('#purchase_id option:selected').data('due');
            $('#amount').removeAttr('max');
            if (invoiceDue !== undefined) {
                $('#amount').attr('max', invoiceDue);
                if (!$('#amount').val()) $('#amount').val(invoiceDue);
            } else if (!$('#amount').val() && due > 0) {
                $('#amount').val(due);
            }
        }

        $('input[name=type], #purchase_id').on('change', refresh);
        refresh();

        // duibar click e duita payment na
        $('#supplierPaymentForm').on('submit', function () {
            $(this).find('button[type=submit]').prop('disabled', true);
        });
    });
</script>
@endpush
