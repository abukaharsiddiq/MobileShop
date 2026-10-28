@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h5 class="m-0">Commission Rules</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light"><tr><th>Rule</th><th>Applies to</th><th>Basis</th><th class="text-right">Rate</th><th>Valid</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse($rules as $r)
                                <tr class="{{ $r->status ? '' : 'text-muted' }}">
                                    <td>{{ $r->name }}</td>
                                    <td>{{ $r->scopeLabel() }}</td>
                                    <td>{{ $r->basisLabel() }}</td>
                                    <td class="text-right">{{ $r->rateLabel() }}</td>
                                    <td class="small">{{ $r->effective_from?->format('d-m-Y') ?? 'always' }} - {{ $r->effective_to?->format('d-m-Y') ?? 'open' }}</td>
                                    <td>{{ $r->status ? 'Active' : 'Inactive' }}</td>
                                    <td><form method="POST" action="{{ route('commission.rules.toggle', $r->id) }}">@csrf
                                        <button class="btn btn-xs btn-outline-secondary">{{ $r->status ? 'Deactivate' : 'Activate' }}</button></form></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">No rules yet. Without a rule no commission is calculated.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <p class="small text-muted mb-0">For each sold line the most specific active rule is used: product, then category, then product type;
                        a rule for one employee beats a role rule, which beats a rule for everyone. Per-invoice rules are added once per invoice.
                        Used rules cannot be edited - deactivate and add a new one.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="m-0">Add rule</h6></div>
                <form method="POST" action="{{ route('commission.rules.store') }}" class="card-body">
                    @csrf
                    <div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" value="{{ old('name') }}" required placeholder="e.g. Smartphone 1%"></div>
                    <div class="form-row">
                        <div class="form-group col-7"><label>Basis</label>
                            <select name="basis" class="form-control">@foreach(\App\Models\CommissionRule::BASES as $k => $l)<option value="{{ $k }}" @selected(old('basis') === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="form-group col-5"><label>Rate (% or Tk)</label><input type="number" step="0.01" min="0.01" name="rate" class="form-control" value="{{ old('rate') }}" required></div>
                    </div>
                    <div class="form-group"><label>Employee</label>
                        <select name="user_id" class="form-control select2bs4"><option value="">Anyone</option>
                            @foreach($people as $p)<option value="{{ $p->id }}" @selected(old('user_id') == $p->id)>{{ $p->name }} ({{ $p->role }})</option>@endforeach</select></div>
                    <div class="form-group"><label>Role</label>
                        <select name="role" class="form-control"><option value="">Any role</option>
                            @foreach(\App\Models\User::staffRoles() as $role)<option value="{{ $role }}" @selected(old('role') === $role)>{{ config("roles.{$role}.label") }}</option>@endforeach</select></div>
                    <div class="form-group"><label>Product</label>
                        <select name="product_id" class="form-control select2bs4"><option value="">Any product</option>
                            @foreach($products as $p)<option value="{{ $p->id }}" @selected(old('product_id') == $p->id)>{{ $p->product_name }} {{ $p->model_number }}</option>@endforeach</select></div>
                    <div class="form-row">
                        <div class="form-group col-6"><label>Category</label>
                            <select name="category_id" class="form-control"><option value="">Any</option>
                                @foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
                        <div class="form-group col-6"><label>Product type</label>
                            <select name="product_type" class="form-control"><option value="">Any</option>
                                @foreach(\App\Models\Product::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('product_type') === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="form-group col-6"><label>From</label><input type="date" name="effective_from" class="form-control" value="{{ old('effective_from') }}"></div>
                        <div class="form-group col-6"><label>To</label><input type="date" name="effective_to" class="form-control" value="{{ old('effective_to') }}"></div>
                    </div>
                    <button class="btn btn-primary">Add rule</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
