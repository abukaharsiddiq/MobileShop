@extends('back-end.master')

@section('admin-title')
Admin Dashboard
@endsection

@php
    $k = $d['kpi'];
    $p = $d['prev'];
    $money = fn ($v) => '৳' . number_format((float) $v, 0);
    $delta = fn ($now, $before) => (float) $before == 0.0 ? null : round(((float) $now - (float) $before) / abs((float) $before) * 100, 1);
    $query = fn (array $extra) => array_filter(array_merge(['range' => $d['range'], 'branch_id' => $branchId], $extra));
    $branchName = $branchId ? optional($branches->firstWhere('id', $branchId))->name : 'All branches';
    $periodLabel = $d['from']->equalTo($d['to']) ? $d['from']->format('d M Y') : $d['from']->format('d M') . ' - ' . $d['to']->format('d M Y');
    $prevLabel = $d['prevFrom']->equalTo($d['prevTo']) ? $d['prevFrom']->format('d M') : $d['prevFrom']->format('d M') . ' - ' . $d['prevTo']->format('d M');
@endphp

@push('admin-styles')
<style>
    /* chart er rong (validated: light #ffffff, dark #111827 surface) - text e kokhono na */
    .dash {
        --series-1: #2a78d6; --series-2: #eb6834; --series-3: #1baf7a; --series-4: #eda100; --series-5: #e87ba4; --series-6: #008300;
        --series-muted: #b4bccb;
    }
    [data-theme="dark"] .dash {
        --series-1: #3987e5; --series-2: #d95926; --series-3: #199e70; --series-4: #c98500; --series-5: #d55181; --series-6: #008300;
        --series-muted: #4b566b;
    }
    .dash .dash-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
    .dash .dash-head h1 { font-size: 1.45rem; font-weight: 700; margin: 0; color: var(--ink); letter-spacing: -.01em; }
    .dash .dash-head p { margin: 2px 0 0; color: var(--muted); font-size: .88rem; }
    .dash .filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 18px; }
    .dash .seg { display: inline-flex; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 3px; flex-wrap: wrap; }
    .dash .seg a { padding: 5px 12px; border-radius: 7px; color: var(--ink-2); font-size: .82rem; font-weight: 500; text-decoration: none; }
    .dash .seg a:hover { background: var(--hover); }
    .dash .seg a.on { background: var(--primary); color: #fff; }
    .dash .filters select { width: auto; min-width: 170px; font-size: .84rem; height: 34px; }
    .dash .imei-box { display: flex; gap: 6px; }
    .dash .imei-box input { width: 220px; height: 34px; font-size: .84rem; }

    .dash .panel { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 18px 20px; height: 100%; }
    .dash .panel-title { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 12px; }
    .dash .panel-title h6 { margin: 0; font-size: .92rem; font-weight: 600; color: var(--ink); }
    .dash .panel-title small, .dash .sub { color: var(--muted); font-size: .78rem; }

    .dash .hero { display: flex; flex-direction: column; justify-content: space-between; background: linear-gradient(160deg, var(--primary-50), var(--surface) 70%); }
    .dash .hero .label { color: var(--ink-2); font-size: .86rem; font-weight: 500; }
    .dash .hero .value { font-size: 52px; font-weight: 700; line-height: 1.05; color: var(--ink); letter-spacing: -.02em; margin: 6px 0; }
    .dash .hero .split { display: flex; gap: 22px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line); }
    .dash .hero .split div { font-size: .8rem; color: var(--muted); }
    .dash .hero .split strong { display: block; color: var(--ink); font-size: 1rem; font-weight: 600; }

    .dash .tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; height: 100%; }
    @media (max-width: 991px) { .dash .tiles { grid-template-columns: repeat(2, 1fr); } }
    .dash .tile { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 14px 16px; position: relative; }
    .dash .tile .t-label { color: var(--muted); font-size: .8rem; font-weight: 500; display: flex; align-items: center; gap: 7px; }
    .dash .tile .t-label i { width: 26px; height: 26px; border-radius: 8px; display: inline-grid; place-items: center; background: var(--primary-50); color: var(--primary-ink); font-size: .75rem; }
    .dash .tile .t-value { font-size: 1.45rem; font-weight: 700; color: var(--ink); margin-top: 8px; letter-spacing: -.01em; }
    .dash .tile .t-foot { font-size: .76rem; color: var(--muted); margin-top: 2px; }
    .dash .delta { font-size: .76rem; font-weight: 600; white-space: nowrap; }
    .dash .delta.good { color: var(--success-ink); }
    .dash .delta.bad { color: var(--danger-ink); }
    .dash .delta.flat { color: var(--muted); }

    .dash .chart-box { position: relative; height: 290px; }
    .dash .chart-box.short { height: 230px; }
    .dash .legend { display: flex; gap: 16px; flex-wrap: wrap; font-size: .78rem; color: var(--ink-2); }
    .dash .legend span { display: inline-flex; align-items: center; gap: 6px; }
    .dash .key-line { width: 16px; height: 2px; border-radius: 2px; display: inline-block; }
    .dash .key-box { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }

    .dash details.viz-table { margin-top: 10px; font-size: .8rem; }
    .dash details.viz-table summary { color: var(--muted); cursor: pointer; }
    .dash details.viz-table table { margin-top: 8px; font-variant-numeric: tabular-nums; }

    .dash .stack { display: flex; gap: 2px; height: 22px; border-radius: 6px; overflow: hidden; background: var(--surface); }
    .dash .stack span { display: block; min-width: 3px; }
    .dash .stack span:first-child { border-radius: 6px 0 0 6px; }
    .dash .stack span:last-child { border-radius: 0 6px 6px 0; }
    .dash .stack span:hover { filter: brightness(1.08); }
    .dash .stack-legend { list-style: none; padding: 0; margin: 14px 0 0; font-size: .82rem; }
    .dash .stack-legend li { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--line-2); color: var(--ink-2); }
    .dash .stack-legend li:last-child { border-bottom: 0; }
    .dash .stack-legend .v { margin-left: auto; color: var(--ink); font-weight: 600; font-variant-numeric: tabular-nums; }
    .dash .stack-legend .pc { width: 46px; text-align: right; color: var(--muted); font-variant-numeric: tabular-nums; }

    .dash .stock-panel { display: flex; flex-direction: column; }
    .dash .stock-facts { display: flex; gap: 10px; margin-top: auto; padding-top: 14px; }
    .dash .stock-facts div { flex: 1; background: var(--surface-2); border: 1px solid var(--line-2); border-radius: var(--radius-sm); padding: 10px 12px; font-size: .76rem; color: var(--muted); }
    .dash .stock-facts strong { display: block; font-size: 1.15rem; color: var(--ink); font-weight: 700; }
    .dash .stock-facts i { margin-right: 4px; }
    .dash .heat { display: grid; grid-template-columns: 34px repeat(14, 1fr); gap: 3px; font-size: .68rem; color: var(--muted); }
    .dash .heat .c { aspect-ratio: 1.6 / 1; border-radius: 4px; background: var(--surface-3); outline-offset: 1px; }
    .dash .heat .c:hover, .dash .heat .c:focus { outline: 2px solid var(--ink-2); }
    .dash .heat .d { display: flex; align-items: center; }
    .dash .heat .h { text-align: center; }
    .dash .heat-scale { display: flex; align-items: center; gap: 4px; justify-content: flex-end; margin-top: 10px; font-size: .72rem; color: var(--muted); }
    .dash .heat-scale i { width: 16px; height: 10px; border-radius: 3px; display: inline-block; }

    .dash .ops a { display: flex; align-items: center; gap: 10px; padding: 9px 4px; border-bottom: 1px solid var(--line-2); color: var(--ink-2); text-decoration: none; font-size: .85rem; }
    .dash .ops a:last-child { border-bottom: 0; }
    .dash .ops a:hover { background: var(--hover); }
    .dash .ops i { width: 28px; height: 28px; border-radius: 8px; display: inline-grid; place-items: center; background: var(--surface-3); color: var(--muted); font-size: .78rem; }
    .dash .ops .n { margin-left: auto; font-weight: 700; color: var(--ink); min-width: 26px; text-align: center; border-radius: 999px; padding: 1px 8px; background: var(--surface-3); }
    .dash .ops .n.hot { background: var(--warning-50); color: var(--warning-ink); }

    .dash .mini-table { width: 100%; font-size: .84rem; }
    .dash .mini-table td, .dash .mini-table th { padding: 8px 4px; border-bottom: 1px solid var(--line-2); vertical-align: middle; }
    .dash .mini-table tr:last-child td { border-bottom: 0; }
    .dash .mini-table th { color: var(--muted); font-weight: 500; font-size: .76rem; text-transform: uppercase; letter-spacing: .03em; }
    .dash .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .dash .inline-bar { height: 6px; border-radius: 3px; background: var(--series-1); opacity: .85; }
    .dash .inline-track { background: var(--surface-3); border-radius: 3px; }
    .dash .empty { color: var(--muted); font-size: .84rem; text-align: center; padding: 26px 0; }
    .dash .row > [class*="col-"] { margin-bottom: 18px; }
</style>
@endpush

@section('admin-content')
<div class="container-fluid dash">

    <div class="dash-head">
        <div>
            <h1>Business overview</h1>
            <p>{{ $branchName }} · {{ $periodLabel }} <span class="sub">(compared with {{ $prevLabel }})</span></p>
        </div>
        <div class="imei-box">
            <input type="text" id="imei_input" class="form-control" placeholder="Quick IMEI / serial lookup" aria-label="IMEI lookup">
            <button class="btn btn-primary btn-sm" type="button" id="search_btn"><i class="fas fa-search"></i></button>
        </div>
    </div>
    <div id="search_result" class="mb-3" style="display:none;"><div class="alert mb-0" id="result_content"></div></div>

    {{-- filter: ekta sari, nicher shob kichu ei somoy/branch e --}}
    <div class="filters">
        <div class="seg" role="group" aria-label="Date range">
            @foreach($ranges as $key => $label)
                <a href="{{ route('admin.dashboard', $query(['range' => $key])) }}" class="{{ $d['range'] === $key ? 'on' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
        <form method="GET" action="{{ route('admin.dashboard') }}">
            <input type="hidden" name="range" value="{{ $d['range'] }}">
            <select name="branch_id" class="form-control" onchange="this.form.submit()" aria-label="Branch">
                <option value="">All branches</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}{{ $b->type === 'warehouse' ? ' (warehouse)' : '' }}</option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- hero + KPI --}}
    <div class="row">
        <div class="col-lg-4">
            @php $dn = $delta($k['net_sales'], $p['net_sales']); @endphp
            <div class="panel hero">
                <div>
                    <div class="label">Net sales</div>
                    <div class="value">{{ $money($k['net_sales']) }}</div>
                    @if($dn !== null)
                        <span class="delta {{ $dn > 0 ? 'good' : ($dn < 0 ? 'bad' : 'flat') }}"><i class="fas fa-arrow-{{ $dn >= 0 ? 'up' : 'down' }}"></i> {{ abs($dn) }}%</span>
                        <span class="sub">vs previous period ({{ $money($p['net_sales']) }})</span>
                    @else
                        <span class="sub">No sales in the previous period</span>
                    @endif
                </div>
                <div class="split">
                    <div><strong>{{ $money($k['sales']) }}</strong>Invoiced</div>
                    <div><strong>{{ $money($k['returns']) }}</strong>Returned</div>
                    <div><strong>{{ number_format($k['units']) }}</strong>Units sold</div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            @php
                $tiles = [
                    ['Gross profit', 'fa-chart-line', $money($k['profit']), $k['margin'] . '% margin', $delta($k['profit'], $p['profit']), true],
                    ['Invoices', 'fa-receipt', number_format($k['invoices']), 'Avg ' . $money($k['avg_ticket']), $delta($k['invoices'], $p['invoices']), true],
                    ['Cash collected', 'fa-hand-holding-usd', $money($k['collected']), 'Cash, bank & mobile', $delta($k['collected'], $p['collected']), true],
                    ['Expenses', 'fa-wallet', $money($k['expenses']), 'From the books', $delta($k['expenses'], $p['expenses']), false],
                    ['Customer dues', 'fa-user-clock', $money($d['receivable']), 'Outstanding today', null, false],
                    ['Stock value', 'fa-boxes', $money($d['stock']['value']), number_format($d['stock']['units']) . ' units at cost', null, true],
                ];
            @endphp
            <div class="tiles">
                @foreach($tiles as [$label, $icon, $value, $foot, $change, $upIsGood])
                    <div class="tile">
                        <div class="t-label"><i class="fas {{ $icon }}"></i> {{ $label }}</div>
                        <div class="t-value">{{ $value }}</div>
                        <div class="t-foot">
                            @if($change !== null)
                                @php $good = $change == 0 ? 'flat' : (($change > 0) === $upIsGood ? 'good' : 'bad'); @endphp
                                <span class="delta {{ $good }}"><i class="fas fa-arrow-{{ $change >= 0 ? 'up' : 'down' }}"></i> {{ abs($change) }}%</span> ·
                            @endif
                            {{ $foot }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- trend + payment --}}
    <div class="row">
        <div class="col-lg-8">
            <div class="panel">
                <div class="panel-title">
                    <h6>Sales trend <small>· by {{ $d['trend']['bucket'] }}</small></h6>
                    <div class="legend">
                        <span><i class="key-line" style="background:var(--series-1)"></i> {{ $periodLabel }}</span>
                        <span><i class="key-line" style="background:var(--series-muted)"></i> Previous period</span>
                    </div>
                </div>
                <div class="chart-box"><canvas id="trendChart" role="img" aria-label="Sales trend compared with the previous period"></canvas></div>
                <details class="viz-table">
                    <summary>View as table</summary>
                    <table class="table table-sm mb-0"><thead><tr><th>{{ ucfirst($d['trend']['bucket']) }}</th><th class="num">Sales</th><th class="num">Invoices</th><th class="num">Previous</th></tr></thead>
                        <tbody>@foreach($d['trend']['points'] as $pt)<tr><td>{{ $pt['label'] }}</td><td class="num">{{ $money($pt['current']) }}</td><td class="num">{{ $pt['invoices'] }}</td><td class="num">{{ $money($pt['previous']) }}</td></tr>@endforeach</tbody></table>
                </details>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="panel">
                <div class="panel-title"><h6>Payments received</h6><small>by method</small></div>
                @if($d['payments'])
                    <div class="chart-box short"><canvas id="paymentChart" role="img" aria-label="Payments by method"></canvas></div>
                    <details class="viz-table">
                        <summary>View as table</summary>
                        <table class="table table-sm mb-0"><tbody>@foreach($d['payments'] as $r)<tr><td>{{ $r['label'] }}</td><td class="num">{{ $r['count'] }}</td><td class="num">{{ $money($r['value']) }}</td></tr>@endforeach</tbody></table>
                    </details>
                @else
                    <div class="empty">No payments in this period.</div>
                @endif
            </div>
        </div>
    </div>

    {{-- category + branch --}}
    <div class="row">
        <div class="{{ $d['branches'] && count($d['branches']) > 1 ? 'col-lg-6' : 'col-lg-12' }}">
            <div class="panel">
                <div class="panel-title"><h6>Sales by category</h6><small>line totals after returns</small></div>
                @if($d['categories'])
                    <div class="chart-box short"><canvas id="categoryChart" role="img" aria-label="Sales by category"></canvas></div>
                    <details class="viz-table">
                        <summary>View as table</summary>
                        <table class="table table-sm mb-0"><tbody>@foreach($d['categories'] as $r)<tr><td>{{ $r['label'] }}</td><td class="num">{{ $r['units'] }} units</td><td class="num">{{ $money($r['value']) }}</td></tr>@endforeach</tbody></table>
                    </details>
                @else
                    <div class="empty">No sales in this period.</div>
                @endif
            </div>
        </div>
        @if($d['branches'] && count($d['branches']) > 1)
            <div class="col-lg-6">
                <div class="panel">
                    <div class="panel-title"><h6>Branch performance</h6><small>invoiced sales</small></div>
                    <div class="chart-box short"><canvas id="branchChart" role="img" aria-label="Sales by branch"></canvas></div>
                    <details class="viz-table">
                        <summary>View as table</summary>
                        <table class="table table-sm mb-0"><tbody>@foreach($d['branches'] as $r)<tr><td>{{ $r['label'] }}</td><td class="num">{{ $r['count'] }} invoices</td><td class="num">{{ $money($r['value']) }}</td></tr>@endforeach</tbody></table>
                    </details>
                </div>
            </div>
        @endif
    </div>

    {{-- stock composition + heatmap --}}
    <div class="row">
        <div class="col-lg-5">
            @php $stockTotal = array_sum(array_column($d['stockTypes'], 'value')); @endphp
            <div class="panel stock-panel">
                <div class="panel-title"><h6>Stock value by type</h6><small>{{ $money($stockTotal) }} at cost</small></div>
                @if($stockTotal > 0)
                    <div class="stack" role="img" aria-label="Stock value split by product type">
                        @foreach($d['stockTypes'] as $i => $r)
                            <span style="flex: {{ max(0.5, $r['value'] / $stockTotal * 100) }}; background: var(--series-{{ $i + 1 }})" title="{{ $r['label'] }}: {{ $money($r['value']) }}"></span>
                        @endforeach
                    </div>
                    <ul class="stack-legend">
                        @foreach($d['stockTypes'] as $i => $r)
                            <li><i class="key-box" style="background: var(--series-{{ $i + 1 }})"></i> {{ $r['label'] }}
                                <span class="v">{{ $money($r['value']) }}</span><span class="pc">{{ round($r['value'] / $stockTotal * 100, 1) }}%</span></li>
                        @endforeach
                    </ul>
                @else
                    <div class="empty">No stock.</div>
                @endif
                <div class="stock-facts">
                    <div><strong>{{ number_format($d['stock']['units']) }}</strong>Units in stock</div>
                    <div><strong>{{ $d['lowStock']['count'] - $d['lowStock']['out'] }}</strong><i class="fas fa-exclamation-triangle"></i> Low</div>
                    <div><strong>{{ $d['lowStock']['out'] }}</strong><i class="fas fa-times-circle"></i> Out of stock</div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            @php $heat = $d['heatmap']; $max = max(1, $heat['max']); @endphp
            <div class="panel">
                <div class="panel-title"><h6>Busiest hours</h6><small>invoices by day and hour</small></div>
                <div class="heat" role="table" aria-label="Invoices by weekday and hour">
                    <div></div>
                    @foreach($heat['hours'] as $h)<div class="h">{{ $h % 12 ?: 12 }}{{ $h < 12 ? 'a' : 'p' }}</div>@endforeach
                    @foreach($heat['days'] as $dayNo => $dayName)
                        <div class="d">{{ $dayName }}</div>
                        @foreach($heat['hours'] as $h)
                            @php $n = $heat['grid'][$dayNo][$h] ?? 0; $level = $n ? (int) ceil($n / $max * 4) : 0; @endphp
                            <div class="c" tabindex="0" title="{{ $dayName }} {{ $h % 12 ?: 12 }}{{ $h < 12 ? ' AM' : ' PM' }}: {{ $n }} invoice(s)"
                                 aria-label="{{ $dayName }} {{ $h }}:00, {{ $n }} invoices"
                                 @if($level) style="background: color-mix(in srgb, var(--series-1) {{ [0, 22, 45, 70, 100][$level] }}%, var(--surface))" @endif></div>
                        @endforeach
                    @endforeach
                </div>
                <div class="heat-scale">Fewer
                    @foreach([22, 45, 70, 100] as $mix)<i style="background: color-mix(in srgb, var(--series-1) {{ $mix }}%, var(--surface))"></i>@endforeach
                    More · busiest slot {{ $heat['max'] }} invoice(s)
                </div>
            </div>
        </div>
    </div>

    {{-- operations + top products --}}
    <div class="row">
        <div class="col-lg-4">
            <div class="panel">
                <div class="panel-title"><h6>Needs attention</h6><small>right now</small></div>
                <div class="ops">
                    @foreach($d['operations'] as $op)
                        <a href="{{ route($op['route']) }}"><i class="fas {{ $op['icon'] }}"></i> {{ $op['label'] }}
                            <span class="n {{ $op['count'] > 0 ? 'hot' : '' }}">{{ $op['count'] }}</span></a>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            @php $topMax = max(1, (float) ($d['topProducts']->max('revenue') ?? 1)); @endphp
            <div class="panel">
                <div class="panel-title"><h6>Top products</h6><small>by revenue</small></div>
                @if($d['topProducts']->isNotEmpty())
                    <table class="mini-table">
                        <thead><tr><th>Product</th><th style="width:28%"></th><th class="num">Units</th><th class="num">Revenue</th><th class="num">Profit</th></tr></thead>
                        <tbody>
                            @foreach($d['topProducts'] as $tp)
                                <tr>
                                    <td><strong>{{ $tp->product_name }}</strong> <span class="sub">{{ $tp->model_number }}</span></td>
                                    <td><div class="inline-track"><div class="inline-bar" style="width: {{ round($tp->revenue / $topMax * 100) }}%"></div></div></td>
                                    <td class="num">{{ (int) $tp->qty }}</td>
                                    <td class="num">{{ $money($tp->revenue) }}</td>
                                    <td class="num">{{ $money($tp->profit) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="empty">No sales in this period.</div>
                @endif
            </div>
        </div>
    </div>

    {{-- dues + low stock + recent --}}
    <div class="row">
        <div class="col-lg-4">
            <div class="panel">
                <div class="panel-title"><h6>Top customer dues</h6><small>{{ $money($d['receivable']) }} total</small></div>
                <table class="mini-table">
                    @forelse($d['dueCustomers'] as $c)
                        <tr><td>{{ $c->name }}<br><span class="sub">{{ $c->phone }}</span></td><td class="num"><strong>{{ $money($c->due) }}</strong></td></tr>
                    @empty
                        <tr><td class="empty">No pending dues.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="panel">
                <div class="panel-title"><h6>Low stock</h6>
                    <small>{{ $d['lowStock']['count'] }} variant(s){{ $d['lowStock']['out'] ? ', ' . $d['lowStock']['out'] . ' out of stock' : '' }}</small></div>
                <table class="mini-table">
                    @forelse($d['lowStock']['items'] as $s)
                        <tr><td>{{ $s->variantLabel() }}</td>
                            <td class="num"><span class="badge badge-{{ $s->quantity <= 0 ? 'danger' : 'warning' }}">
                                <i class="fas fa-{{ $s->quantity <= 0 ? 'times-circle' : 'exclamation-triangle' }}"></i> {{ $s->quantity <= 0 ? 'Out' : $s->quantity . ' left' }}</span></td></tr>
                    @empty
                        <tr><td class="empty">All stock above the alert level.</td></tr>
                    @endforelse
                </table>
                @if($d['lowStock']['count'] > 6 && Route::has('reports.low_stock'))<a href="{{ route('reports.low_stock') }}" class="small">View all</a>@endif
            </div>
        </div>
        <div class="col-lg-4">
            <div class="panel">
                <div class="panel-title"><h6>Recent sales</h6><small>latest invoices</small></div>
                <table class="mini-table">
                    @forelse($d['recentSales'] as $s)
                        <tr><td><a href="{{ route('sale.show', $s->id) }}">{{ $s->invoice_no }}</a><br><span class="sub">{{ $s->customer->name ?? 'Walk-in' }} · {{ $s->created_at->diffForHumans() }}</span></td>
                            <td class="num"><strong>{{ $money($s->grand_total) }}</strong>@if($s->due_amount > 0)<br><span class="sub">due {{ $money($s->due_amount) }}</span>@endif</td></tr>
                    @empty
                        <tr><td class="empty">No sales yet.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
(function () {
    const data = {
        trend: @json($d['trend']['points']),
        payments: @json($d['payments']),
        categories: @json($d['categories']),
        branches: @json($d['branches']),
    };
    const charts = [];
    const css = name => getComputedStyle(document.querySelector('.dash')).getPropertyValue(name).trim();
    const compact = new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 });
    const full = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });
    const tk = v => '৳' + full.format(v);

    // bar er sheshe man (text token e, data rong e na)
    const valueLabels = {
        id: 'valueLabels',
        afterDatasetsDraw(chart) {
            const { ctx } = chart;
            ctx.save();
            ctx.font = '600 11px ' + css('--font');
            ctx.fillStyle = css('--ink-2');
            ctx.textBaseline = 'middle';
            chart.getDatasetMeta(0).data.forEach((bar, i) => {
                ctx.fillText('৳' + compact.format(chart.data.datasets[0].data[i]), bar.x + 6, bar.y);
            });
            ctx.restore();
        }
    };
    // line chart e crosshair (pointer er X)
    const crosshair = {
        id: 'crosshair',
        afterDatasetsDraw(chart) {
            const active = chart.tooltip && chart.tooltip.getActiveElements();
            if (!active || !active.length) return;
            const { ctx, chartArea } = chart;
            const x = active[0].element.x;
            ctx.save();
            ctx.strokeStyle = css('--line');
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(x, chartArea.top); ctx.lineTo(x, chartArea.bottom); ctx.stroke();
            ctx.restore();
        }
    };

    function tooltip(extra) {
        return Object.assign({
            backgroundColor: css('--surface'), borderColor: css('--line'), borderWidth: 1, padding: 10, cornerRadius: 10,
            titleColor: css('--muted'), titleFont: { weight: '500', size: 11 }, bodyColor: css('--ink'), bodyFont: { weight: '600', size: 12 },
            usePointStyle: true, boxWidth: 14, boxHeight: 2,
        }, extra || {});
    }

    function hbar(id, rows, color) {
        const el = document.getElementById(id);
        if (!el || !rows.length) return;
        charts.push(new Chart(el, {
            type: 'bar',
            data: { labels: rows.map(r => r.label), datasets: [{ data: rows.map(r => r.value), backgroundColor: color, hoverBackgroundColor: color,
                maxBarThickness: 18, borderRadius: { topRight: 4, bottomRight: 4 }, borderSkipped: 'start' }] },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, layout: { padding: { left: 8, right: 56 } },
                plugins: { legend: { display: false }, datalabels: { display: false },
                    tooltip: tooltip({ callbacks: { label: c => ' ' + tk(c.parsed.x) + (rows[c.dataIndex].units !== undefined ? '  ·  ' + rows[c.dataIndex].units + ' units'
                        : rows[c.dataIndex].count !== undefined ? '  ·  ' + rows[c.dataIndex].count + ' payment(s)' : '') } }) },
                scales: {
                    x: { beginAtZero: true, grid: { color: css('--line-2'), drawBorder: false }, ticks: { color: css('--muted'), font: { size: 11 }, callback: v => compact.format(v) } },
                    y: { grid: { display: false, drawBorder: false }, ticks: { color: css('--ink-2'), font: { size: 12 } }, afterFit: s => { s.width += 10; } },
                },
            },
            plugins: [valueLabels],
        }));
    }

    function render() {
        charts.splice(0).forEach(c => c.destroy());
        Chart.defaults.font.family = css('--font');
        const blue = css('--series-1'), gray = css('--series-muted'), surface = css('--surface');

        const trend = document.getElementById('trendChart');
        if (trend) {
            const g = trend.getContext('2d').createLinearGradient(0, 0, 0, 290);
            g.addColorStop(0, blue + '26'); g.addColorStop(1, blue + '00');
            charts.push(new Chart(trend, {
                type: 'line',
                data: { labels: data.trend.map(p => p.label), datasets: [
                    { label: 'This period', data: data.trend.map(p => p.current), borderColor: blue, backgroundColor: g, fill: true, borderWidth: 2,
                      cubicInterpolationMode: 'monotone', pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: blue, pointHoverBorderColor: surface, pointHoverBorderWidth: 2 },
                    { label: 'Previous period', data: data.trend.map(p => p.previous), borderColor: gray, borderWidth: 2, fill: false,
                      cubicInterpolationMode: 'monotone', pointRadius: 0, pointHoverRadius: 4, pointHoverBackgroundColor: gray, pointHoverBorderColor: surface, pointHoverBorderWidth: 2 },
                ] },
                options: {
                    responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false }, datalabels: { display: false },
                        tooltip: tooltip({ callbacks: { label: c => ' ' + tk(c.parsed.y) + '  ' + c.dataset.label
                            + (c.datasetIndex === 0 ? '  ·  ' + data.trend[c.dataIndex].invoices + ' invoice(s)' : '') } }) },
                    scales: {
                        x: { grid: { display: false, drawBorder: false }, ticks: { color: css('--muted'), font: { size: 11 }, maxRotation: 0, autoSkipPadding: 14 } },
                        y: { beginAtZero: true, grid: { color: css('--line-2'), drawBorder: false }, ticks: { color: css('--muted'), font: { size: 11 }, callback: v => compact.format(v) } },
                    },
                },
                plugins: [crosshair],
            }));
        }

        hbar('paymentChart', data.payments, blue);
        hbar('categoryChart', data.categories, blue);
        hbar('branchChart', data.branches, blue);
    }

    if (window.Chart) {
        render();
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(render);
        // light/dark bodlale notun rong e abar
        new MutationObserver(render).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    // IMEI lookup (ager moto)
    $('#search_btn').on('click', function () {
        const imei = $('#imei_input').val().trim();
        if (!imei) return;
        const btn = $(this).html('<i class="fas fa-spinner fa-spin"></i>');
        $.get("{{ route('admin.dashboard.imei-search') }}", { imei: imei }).done(function (res) {
            btn.html('<i class="fas fa-search"></i>');
            const box = $('#result_content').removeClass('alert-danger alert-light border').empty();
            $('#search_result').fadeIn();
            if (res.status === 'success') {
                const r = res.data;
                box.addClass('alert-light border')
                    .append($('<strong>').text(r.product_name)).append(' ')
                    .append($('<span class="text-muted">').text(r.variant)).append(' ')
                    .append($('<span class="badge ml-2">').addClass('badge-' + (r.badge || 'secondary')).text(r.status_text)).append(' ')
                    .append($('<small class="text-muted ml-2">').text(r.sale_info));
            } else {
                box.addClass('alert-danger').text('IMEI not found in our records.');
            }
        });
    });
    $('#imei_input').on('keydown', e => { if (e.key === 'Enter') $('#search_btn').click(); });
})();
</script>
@endpush
