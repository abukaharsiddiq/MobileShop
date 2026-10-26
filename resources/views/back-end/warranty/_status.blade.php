{{-- Warranty er status badge (server e hishab: Warranty::currentStatus) --}}
@php $s = $warranty->currentStatus(); @endphp
<span class="badge badge-{{ ['valid' => 'success', 'expiring_soon' => 'warning', 'expired' => 'secondary', 'claimed' => 'info', 'void' => 'dark', 'replaced' => 'dark'][$s] ?? 'light' }}">
    {{ \App\Models\Warranty::STATUS_LABELS[$s] ?? $s }}@if($s === 'valid' || $s === 'expiring_soon') ({{ $warranty->daysLeft() }} days)@endif
</span>
