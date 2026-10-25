@extends('back-end.master')

@section('admin-title') {{ $name }} @endsection
@section('name') {{ $name }} @endsection
@section('title') {{ $title }} @endsection

@push('admin-styles')
<style>
  a.btn-primary{
    position: absolute;
    right: 0;
  }
</style>
@endpush

@section('admin-content')
<div class="card">
  <div class="card-header">
    <h1 class="card-title">Manage {{ $name }}</h1>
    <a href="{{ route('salesman.create') }}" class="btn btn-sm back-btn">
       <i class="fa fa-plus"></i> Add New 
    </a>
  </div>

  <div class="card-body">
    <div class="table-responsive">
      <table id="zero_config111" class="table table-striped table-bordered zero_config">
        <thead>
          <tr>
            <th>SN</th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Role</th>
            <th>Branch</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          @php $i=1; @endphp
          @foreach($salesmans as $employee)
          <tr>
            <td>{{ $i++ }}</td>
            <td>{{ $employee->name }}</td>
            <td>{{ $employee->email }}</td>
            <td>{{ $employee->phone }}</td>
            <td>{{ $employee->roleLabel() }}</td>
            <td>{{ $branches[$employee->branch_id] ?? 'All branches' }}</td>
            <td>
              @if($employee->isActive())
                <span class="badge badge-success">Active</span>
              @else
                <span class="badge badge-secondary">Inactive</span>
              @endif
            </td>
            <td>
              <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fa fa-ellipsis-v"></i>
              </button>
              <div class="dropdown-menu" role="menu">
                 <a class="dropdown-item" href="{{ route('salesman.edit', ['id'=>$employee->id]) }}">
                    <i class="fa fa-edit"></i> Update
                 </a>
                 <form id="deleteForm-{{ $employee->id }}" action="{{ route('salesman.delete', ['id'=>$employee->id]) }}" method="POST" style="display:inline;">
                    @csrf
                    @method('DELETE')
                    <a href="javascript:void(0)" onclick="confirmDelete({{ $employee->id }})" class="dropdown-item">
                        <i class="fa fa-trash"></i> Delete
                    </a>
                 </form>
              </div>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection

@push('admin-scripts')
<script>
function confirmDelete(id){
    if(confirm('Delete this user? If the user has sales or other history, the account will be deactivated instead.')){
        document.getElementById('deleteForm-'+id).submit();
    }
}
</script>
@endpush
