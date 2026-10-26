{{-- Ledger/statement er table. $statement = SupplierAccountService::statement(); $links = false hole (print) link na --}}
@php
    $links = $links ?? true;
    $label = fn ($b) => $b > 0 ? 'Payable' : ($b < 0 ? 'Advance' : '');
    $badge = [
        \App\Models\SupplierLedger::OPENING         => 'badge-dark',
        \App\Models\SupplierLedger::PURCHASE        => 'badge-info',
        \App\Models\SupplierLedger::PAYMENT         => 'badge-success',
        \App\Models\SupplierLedger::PURCHASE_RETURN => 'badge-danger',
        \App\Models\SupplierLedger::REFUND          => 'badge-warning',
        \App\Models\SupplierLedger::PAYMENT_VOID    => 'badge-secondary',
        \App\Models\SupplierLedger::REFUND_VOID     => 'badge-secondary',
    ];
@endphp
<table class="table table-bordered table-striped table-sm">
    <thead>
        <tr>
            <th>Date</th>
            <th>Reference</th>
            <th>Type</th>
            <th class="text-right">Debit (Paid / Returned)</th>
            <th class="text-right">Credit (Purchase / Payable)</th>
            <th class="text-right">Balance</th>
            <th>Note</th>
        </tr>
    </thead>
    <tbody>
        @if($statement['from'])
        <tr class="font-weight-bold">
            <td>{{ date('d-m-Y', strtotime($statement['from'])) }}</td>
            <td colspan="4">Balance brought forward</td>
            <td class="text-right">{{ number_format(abs($statement['opening']), 2) }} <small>{{ $label($statement['opening']) }}</small></td>
            <td></td>
        </tr>
        @endif
        @forelse($statement['rows'] as $row)
        <tr>
            <td>{{ date('d-m-Y', strtotime($row->date)) }}</td>
            <td>
                @if($links && $row->purchase_id && $row->purchase)
                    <a href="{{ route('purchase.show', $row->purchase_id) }}">{{ $row->transaction_type === \App\Models\SupplierLedger::PURCHASE ? $row->reference_no : $row->purchase->invoice_no }}</a>
                    @if($row->supplier_payment_id)<br><small>{{ $row->reference_no }}</small>@endif
                @elseif($links && $row->purchase_return_id)
                    <a href="{{ route('purchase-return.show', $row->purchase_return_id) }}">{{ $row->reference_no }}</a>
                @else
                    {{ $row->reference_no ?: '-' }}
                @endif
            </td>
            <td>
                <span class="badge {{ $badge[$row->transaction_type] ?? 'badge-light' }}">{{ $row->transaction_type }}</span>
                @if($row->payment)
                    <br><small>{{ $row->payment->payment_method }}{{ $row->payment->voided_at && in_array($row->transaction_type, [\App\Models\SupplierLedger::PAYMENT, \App\Models\SupplierLedger::REFUND]) ? ' · voided' : '' }}</small>
                @endif
            </td>
            <td class="text-right text-danger">{{ $row->debit > 0 ? number_format($row->debit, 2) : '' }}</td>
            <td class="text-right text-success">{{ $row->credit > 0 ? number_format($row->credit, 2) : '' }}</td>
            <td class="text-right font-weight-bold">{{ number_format(abs($row->running_balance), 2) }} <small>{{ $label($row->running_balance) }}</small></td>
            <td><small>{{ $row->note }}</small></td>
        </tr>
        @empty
        <tr><td colspan="7" class="text-center text-muted">No transactions in this period.</td></tr>
        @endforelse
    </tbody>
    <tfoot class="font-weight-bold bg-light">
        <tr>
            <td colspan="3" class="text-right">Total:</td>
            <td class="text-right text-danger">{{ number_format($statement['debit'], 2) }}</td>
            <td class="text-right text-success">{{ number_format($statement['credit'], 2) }}</td>
            <td class="text-right">{{ number_format(abs($statement['closing']), 2) }} <small>{{ $label($statement['closing']) }}</small></td>
            <td></td>
        </tr>
    </tfoot>
</table>
