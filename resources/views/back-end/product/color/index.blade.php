@extends('back-end.master')

@section('admin-title')
  {{ $name }}
@endsection

@section('name')
{{ $name }}
@endsection

@section('title')
 {{ $title }}
@endsection

@push('admin-styles')
<style>
	a.btn-primay{
		content:'';
		position: absolute;
		right: 0;
	}
</style>
@endpush

@section('admin-content')
<div class="card">
  <div class="card-header">
                      <h1 class="card-title">Manage {{ $name }}</h1>
                      <a href="{{ route('color.create') }}" class="btn btn-sm back-btn">
                         <i class="fa fa-plus"></i> Add New 
                      </a>
                    </div>
                <div class="card-body">
                  
                  <div class="table-responsive">
                    <table
                      id="zero_config111"
                      class="table table-striped table-bordered"
                    >
                      <thead>
                        <tr>
                          <th>SN</th>
                          <th>Name</th>
                          <th>Slug</th>
                          <th>Status</th>
                          <th>Action</th>
                        </tr>
                      </thead>
                      <tbody>
                      	@php
                      	$i=1
                      	@endphp
                      	@foreach($colors as $info)
                        <tr>
                          <td>{{ $i++ }}</td>
                          <td>{{ $info->color_name }}</td>
                          <td>{{ $info->slug }}</td>
                          <td>
                                <span class="d-none d-print-block">{{ $info->status == '1' ? 'active' : 'inactive' }}</span>
                                <span class="d-print-none">
                                    <label class="switch">
                                        <input type="checkbox" class="status-switch" data-id="{{ $info->id }}" {{ $info->status == '1' ? 'checked' : '' }}>
                                        <span class="slider round"></span>
                                    </label>
                                </span>
                            </td>
                          
                          <td>

                          <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fa fa-ellipsis-v"></i>
                        </button>

                              <div class="dropdown-menu" role="menu" style="">

                                 <a class="dropdown-item" href="{{ route('color.edit',['id'=>$info['id']]) }}">
                                  <i class="fa fa-edit"></i> Update
                                </a>

                               <form id="deleteForm-{{ $info['id'] }}" action="{{ route('color.delete', ['id'=>$info['id']]) }}" method="POST" style="display:inline;">
                                  @csrf
                                  @method('DELETE')
                                  <a href="javascript:void(0)" onclick="confirmDelete({{ $info['id'] }})" class="dropdown-item">
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
    
    $(document).ready(function () {
        $('.status-switch').on('change', function () {
            let id = $(this).data('id');
            let status = $(this).is(':checked') ? 1 : 0;
            let url = `{{ route('color.status.change', ['id' => ':id']) }}`;

            url = url.replace(':id', id);
            updateStatus(url, status);
        });
    });
  </script>
@endpush