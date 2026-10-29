{{-- report er filter sari: tarikh, branch (nijer), ar $slot e onno filter --}}
<form method="GET" class="form-row align-items-end mb-3 d-print-none">
    @if(!isset($noDates))
        <div class="col-md-2 col-6 mb-2"><label class="small mb-1">From</label>
            <input type="date" name="from_date" class="form-control form-control-sm" value="{{ $from }}"></div>
        <div class="col-md-2 col-6 mb-2"><label class="small mb-1">To</label>
            <input type="date" name="to_date" class="form-control form-control-sm" value="{{ $to }}"></div>
    @endif
    <div class="col-md-2 col-6 mb-2"><label class="small mb-1">Branch</label>
        <select name="branch_id" class="form-control form-control-sm">
            @if(auth()->user()->canAccessAllBranches())<option value="">All branches</option>@endif
            @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>@endforeach
        </select></div>
    {{ $slot ?? '' }}
    <div class="col-md-2 col-6 mb-2">
        <button class="btn btn-sm btn-primary"><i class="fa fa-filter"></i> Apply</button>
        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="fa fa-print"></i></button>
    </div>
</form>
