{{-- Tarikh ba financial year diye filter. $from, $to, $years (FinancialYear list), $year (bachai kora), $groupBy?, $keep (hidden field) --}}
<form method="GET" class="mb-3 d-print-none">
    <div class="form-row align-items-end">
        <div class="col-md-3 col-6 mb-2">
            <label class="small mb-1">From</label>
            <input type="date" name="from_date" class="form-control form-control-sm" value="{{ $year ? '' : $from }}">
        </div>
        <div class="col-md-3 col-6 mb-2">
            <label class="small mb-1">To</label>
            <input type="date" name="to_date" class="form-control form-control-sm" value="{{ $year ? '' : $to }}">
        </div>
        <div class="col-md-3 col-12 mb-2">
            <label class="small mb-1">or Financial Year</label>
            <select name="financial_year_id" class="form-control form-control-sm">
                <option value="">-- date range --</option>
                @foreach($years ?? [] as $fy)
                    <option value="{{ $fy->id }}" @selected($year?->id === $fy->id)>{{ $fy->name }}{{ $fy->is_closed ? ' (closed)' : '' }}</option>
                @endforeach
            </select>
        </div>
        @isset($groupBy)
            <div class="col-md-2 col-6 mb-2">
                <label class="small mb-1">Group by</label>
                <select name="group_by" class="form-control form-control-sm">
                    <option value="day" @selected($groupBy === 'day')>Day</option>
                    <option value="month" @selected($groupBy === 'month')>Month</option>
                </select>
            </div>
        @endisset
        <div class="col-md-3 col-12 mb-2">
            <button class="btn btn-primary btn-sm"><i class="fa fa-filter"></i> Apply</button>
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa fa-print"></i> Print</button>
        </div>
    </div>
    @foreach(($keep ?? []) as $field => $value)
        <input type="hidden" name="{{ $field }}" value="{{ $value }}">
    @endforeach
</form>
<p class="small text-muted mb-2">Period: <strong>{{ \Carbon\Carbon::parse($from)->format('d M Y') }}</strong> to <strong>{{ \Carbon\Carbon::parse($to)->format('d M Y') }}</strong>{{ $year ? " ({$year->name})" : '' }}</p>
