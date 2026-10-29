{{-- purono report er form e branch filter (nijer branch gulo; shob branch er access thakle "All") --}}
@php
    $branchUser = auth()->user();
    $branchOptions = \App\Models\Branch::accessibleBy($branchUser)->orderBy('name')->get(['id', 'name']);
    $branchSelected = (int) request('branch_id') ?: ($branchUser->canAccessAllBranches() ? null : (int) $branchUser->branch_id);
@endphp
<div class="{{ $wrap ?? 'form-group mb-2 d-print-none' }}">
    <label class="small mb-1 mr-2">Branch</label>
    <select name="branch_id" class="form-control form-control-sm d-inline-block" style="width:auto;min-width:180px">
        @if($branchUser->canAccessAllBranches())<option value="">All branches</option>@endif
        @foreach($branchOptions as $b)<option value="{{ $b->id }}" @selected($branchSelected === $b->id)>{{ $b->name }}</option>@endforeach
    </select>
</div>
@if($branchSelected)
    <div class="d-none d-print-block small">Branch: {{ $branchOptions->firstWhere('id', $branchSelected)?->name }}</div>
@endif
