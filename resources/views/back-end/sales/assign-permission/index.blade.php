@extends('back-end.master')
@section('admin-title', $name)

@section('admin-content')
<div class="container">
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" id="salesmanSelectForm">
                <div class="mb-3">
                    <label>Select Staff</label>
                    <select name="saleman_id" id="saleman_id" class="form-control" onchange="document.getElementById('salesmanSelectForm').submit();">
                        <option value="">-- Select Staff --</option>
                        @foreach($salemans as $saleman)
                            <option value="{{ $saleman->id }}" {{ request('saleman_id') == $saleman->id ? 'selected' : '' }}>
                                {{ $saleman->name }} ({{ $saleman->roleLabel() }}){{ $saleman->isActive() ? '' : ' - inactive' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>
    </div>

    @if($selected)
    <form action="{{ route('salesman-permission.assign.store') }}" method="POST">
        @csrf
        <input type="hidden" name="saleman_id" value="{{ $selected->id }}">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <span>Role: <strong>{{ $selected->roleLabel() }}</strong></span>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="load-role-defaults">
                Load {{ $selected->roleLabel() }} defaults
            </button>
        </div>

        @foreach($permissions as $groupName => $groupPermissions)
        <div class="card mb-3">
            <div class="card-header bg-secondary text-white d-flex justify-content-between">
                <span>{{ $groupName ?: 'Other Permissions' }}</span>
                <div>
                    <small>Select All</small>
                    <input type="checkbox" class="select-group" data-group="{{ Str::slug($groupName) }}">
                </div>
            </div>

            <div class="card-body">
                <div class="row">
                    @foreach($groupPermissions as $permission)
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox"
                                class="form-check-input permission-checkbox group-{{ Str::slug($groupName) }}"
                                name="permissions[]"
                                value="{{ $permission->name }}"
                                {{ in_array($permission->name, $assigned) ? 'checked' : '' }}>
                            <label class="form-check-label">
                                {{ $permission->name }}
                            </label>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endforeach

        <button type="submit" class="btn btn-primary btn-lg mb-5">Assign Permission</button>
    </form>
    @endif
</div>
@endsection

@push('admin-scripts')
<script>
    // Group Select/Deselect All logic
    document.querySelectorAll('.select-group').forEach(groupToggle => {
        groupToggle.addEventListener('change', function() {
            let groupClass = '.group-' + this.dataset.group;
            document.querySelectorAll(groupClass).forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    });

    // Role er default permission check kore (save na korle kichu bodlay na)
    @if($selected)
    const roleDefaults = @json($templates[$selected->role] ?? []);
    document.getElementById('load-role-defaults')?.addEventListener('click', function () {
        if (!confirm('Replace the ticked permissions with the role defaults? Nothing is saved until you click Assign Permission.')) {
            return;
        }
        document.querySelectorAll('.permission-checkbox').forEach(checkbox => {
            checkbox.checked = roleDefaults.includes(checkbox.value);
        });
    });
    @endif
</script>
@endpush
