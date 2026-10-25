@extends('back-end.master')
@section('admin-title') {{ $name }} @endsection
@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="m-0">{{ $name }}</h6>
            <a href="{{ route('reports.imei') }}" class="btn btn-sm btn-outline-secondary">Search IMEI</a>
        </div>
        <div class="card-body">
            {{-- status onujayi sonkhya --}}
            <div class="mb-3">
                @foreach(\App\Models\ProductImei::STATUSES as $value => $label)
                    <a href="{{ route('reports.imei.stock', array_merge(request()->except('page'), ['status' => $value])) }}"
                       class="badge badge-{{ (string) $status === (string) $value ? 'primary' : 'light' }} p-2 mr-1">
                        {{ $label }}: {{ $counts[$value] ?? 0 }}
                    </a>
                @endforeach
            </div>

            <form method="GET" class="form-row align-items-end mb-3">
                <div class="col-md-3">
                    <label class="small mb-0">Status</label>
                    <select name="status" class="form-control form-control-sm">
                        <option value="owned" {{ $status === 'owned' ? 'selected' : '' }}>In our stock (all)</option>
                        <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Every record</option>
                        @foreach(\App\Models\ProductImei::STATUSES as $value => $label)
                            <option value="{{ $value }}" {{ (string) $status === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="small mb-0">Product</label>
                    <select name="product_id" class="form-control form-control-sm">
                        <option value="">All products</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}" {{ request('product_id') == $product->id ? 'selected' : '' }}>{{ $product->product_name }} ({{ $product->model_number }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="small mb-0">Branch</label>
                    <select name="branch_id" class="form-control form-control-sm">
                        <option value="">All branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-sm btn-primary">Filter</button></div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr><th>#</th><th>Product / Variant</th><th>IMEI 1</th><th>IMEI 2</th><th>Status</th><th>Branch</th><th>Since</th></tr>
                    </thead>
                    <tbody>
                        @forelse($imeis as $imei)
                            <tr>
                                <td>{{ $imeis->firstItem() + $loop->index }}</td>
                                <td>{{ $imei->product->product_name ?? '-' }} <small class="text-muted">{{ $imei->mainStock?->attributeLabel() }}</small></td>
                                <td><a href="{{ route('reports.imei', ['imei_no' => $imei->imei_no]) }}">{{ $imei->imei_no }}</a></td>
                                <td>{{ $imei->imei_no_2 ?? '-' }}</td>
                                <td>
                                    <span class="badge badge-{{ $imei->statusBadge() }}">{{ $imei->statusText() }}</span>
                                    @if($imei->reservedCustomer) <small>{{ $imei->reservedCustomer->name }}</small> @endif
                                </td>
                                <td>{{ $imei->branch->name ?? '-' }}</td>
                                <td>{{ ($imei->status_changed_at ?? $imei->updated_at)?->format('d M, Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">No IMEI found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $imeis->links() }}
        </div>
    </div>
</div>
@endsection
