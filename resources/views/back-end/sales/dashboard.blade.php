@extends('back-end.master')

@section('admin-title', 'Dashboard')

@section('admin-content')
@php
    $hour = (int) now()->format('H');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-3">
        <div>
            <h3 class="mb-1">{{ $greeting }}, {{ \Illuminate\Support\Str::of($user->name)->before(' ') }}</h3>
            <p class="text-muted mb-0">{{ now()->format('l, d F Y') }} &middot; {{ config("roles.{$user->role}.label", $user->role) }}</p>
        </div>
        @can('create sale')
            <a href="{{ route('sale.create') }}" class="btn btn-primary mt-2"><i class="fas fa-cash-register mr-1"></i> New sale</a>
        @endcan
    </div>

    {{-- ajker hishab --}}
    <div class="row">
        @isset($todaySales)
            <div class="col-xl-3 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-info">
                        <span class="stats-label">My sales today</span>
                        <span class="stats-number">{{ $money($todaySales->total) }}</span>
                        <small class="text-muted">{{ (int) $todaySales->invoices }} invoice(s) &middot; received {{ $money($todaySales->paid) }}</small>
                    </div>
                    <div class="stats-icon"><i class="fas fa-shopping-bag"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-3">
                <div class="stats-card">
                    <div class="stats-info">
                        <span class="stats-label">My sales this month</span>
                        <span class="stats-number">{{ $money($monthSales) }}</span>
                        <small class="text-muted">{{ now()->format('F Y') }}</small>
                    </div>
                    <div class="stats-icon" style="background: linear-gradient(135deg, #22c55e, #059669)"><i class="fas fa-chart-line"></i></div>
                </div>
            </div>
        @endisset

        @isset($installmentsOverdue)
            <div class="col-xl-3 col-sm-6 mb-3">
                <a href="{{ route('installments.due') }}" class="d-block text-reset text-decoration-none">
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Installments</span>
                            <span class="stats-number">{{ $installmentsToday }} <small class="text-muted" style="font-size: .9rem">due today</small></span>
                            <small class="{{ $installmentsOverdue ? 'text-danger' : 'text-muted' }}">{{ $installmentsOverdue }} overdue</small>
                        </div>
                        <div class="stats-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706)"><i class="fas fa-calendar-check"></i></div>
                    </div>
                </a>
            </div>
        @endisset

        @isset($openClaims)
            <div class="col-xl-3 col-sm-6 mb-3">
                <a href="{{ route('warranty.claims', ['status' => 'open']) }}" class="d-block text-reset text-decoration-none">
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Warranty claims</span>
                            <span class="stats-number">{{ $openClaims }} <small class="text-muted" style="font-size: .9rem">open</small></span>
                            <small class="{{ $readyClaims ? 'text-success' : 'text-muted' }}">{{ $readyClaims }} ready for pickup</small>
                        </div>
                        <div class="stats-icon" style="background: linear-gradient(135deg, #0ea5e9, #2563eb)"><i class="fas fa-shield-alt"></i></div>
                    </div>
                </a>
            </div>
        @endisset

        @isset($lowStock)
            <div class="col-xl-3 col-sm-6 mb-3">
                <a href="{{ route('reports.stock') }}" class="d-block text-reset text-decoration-none">
                    <div class="stats-card">
                        <div class="stats-info">
                            <span class="stats-label">Low stock</span>
                            <span class="stats-number">{{ $lowStock }} <small class="text-muted" style="font-size: .9rem">variant(s)</small></span>
                            <small class="text-muted">at or below the reorder level</small>
                        </div>
                        <div class="stats-icon" style="background: linear-gradient(135deg, #f43f5e, #dc2626)"><i class="fas fa-box-open"></i></div>
                    </div>
                </a>
            </div>
        @endisset
    </div>

    <div class="row">
        <div class="col-lg-8">
            {{-- quick action --}}
            @if($actions->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h6>Quick actions</h6></div>
                    <div class="card-body">
                        <div class="row">
                            @foreach($actions as [$permission, $route, $icon, $label, $tone])
                                <div class="col-md-4 col-6 mb-3">
                                    <a href="{{ route($route) }}" class="quick-action d-flex align-items-center text-decoration-none">
                                        <span class="qa-icon qa-{{ $tone }}"><i class="fas {{ $icon }}"></i></span>
                                        <span class="font-weight-bold">{{ $label }}</span>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @isset($recentSales)
                <div class="card">
                    <div class="card-header">
                        <h6>My recent sales</h6>
                        @can('read sale')<a href="{{ route('sale.index') }}" class="btn btn-sm back-btn">All sales</a>@endcan
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th class="text-right">Total</th><th class="text-right">Due</th></tr></thead>
                                <tbody>
                                    @forelse($recentSales as $sale)
                                        <tr>
                                            <td>@can('read sale')<a href="{{ route('sale.show', $sale->id) }}">{{ $sale->invoice_no }}</a>@else{{ $sale->invoice_no }}@endcan</td>
                                            <td>{{ \Carbon\Carbon::parse($sale->sale_date)->format('d M') }}</td>
                                            <td>{{ $sale->customer->name ?? '-' }}</td>
                                            <td class="text-right">{{ $money($sale->grand_total) }}</td>
                                            <td class="text-right {{ $sale->due_amount > 0 ? 'text-danger' : 'text-success' }}">{{ $money($sale->due_amount) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-center text-muted py-4">No sales yet. Your sales will show here.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endisset
        </div>

        <div class="col-lg-4">
            {{-- cash drawer --}}
            @can('open cash session')
                <div class="card">
                    <div class="card-header"><h6>My cash drawer</h6></div>
                    <div class="card-body">
                        @if($cashSession)
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted">{{ $cashSession->session_no }}</span>
                                <span class="badge badge-{{ ['open' => 'primary', 'closed' => 'warning', 'recount' => 'danger', 'approved' => 'success'][$cashSession->status] ?? 'secondary' }}">{{ $cashSession->statusLabel() }}</span>
                            </div>
                            <small class="text-muted text-uppercase">Expected cash now</small>
                            <div class="h3 mb-3">{{ $money($cashExpected) }}</div>
                            <a href="{{ route('cash-closing.show', $cashSession->id) }}" class="btn btn-outline-primary btn-block">
                                {{ $cashSession->isOpen() ? 'Open drawer / close day' : 'View session' }}
                            </a>
                        @else
                            <p class="text-muted">No cash session today. Count the drawer and open one before taking cash.</p>
                            <a href="{{ route('cash-closing.index') }}" class="btn btn-primary btn-block"><i class="fas fa-lock-open mr-1"></i> Open today's session</a>
                        @endif
                    </div>
                </div>
            @endcan

            <div class="card">
                <div class="card-header"><h6>Tips</h6></div>
                <div class="card-body small text-muted">
                    <p class="mb-2"><i class="fas fa-moon mr-1"></i> Use the moon / sun button at the top to switch between light and dark mode.</p>
                    <p class="mb-0"><i class="fas fa-barcode mr-1"></i> In the POS, scan the IMEI or barcode straight into the search box (F2).</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
