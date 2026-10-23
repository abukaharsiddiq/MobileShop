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
                      <a href="{{ route('branch.create') }}" class="btn btn-sm back-btn">
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
                          <th>Email</th>
                          <th>Phone</th>
                          <th>Address</th>
                          <th>Code</th>
                          <th>Status</th>
                          <th>Action</th>
                        </tr>
                      </thead>
                      <tbody>
                      	@php
                      	$i=1
                      	@endphp
                      	@foreach($branches as $info)
                        <tr>
                          <td>{{ $i++ }}</td>
                          <td>{{ $info->name }}</td>
                          <td>{{ $info->email }}</td>
                          <td>{{ $info->phone }}</td>
                          <td>{{ $info->address }}</td>
                          <td>{{ $info->code }}</td>
                          <td>
                            @if($info->status)
                              <span class="badge badge-success">Active</span>
                            @else
                              <span class="badge badge-secondary">Inactive</span>
                            @endif
                            @if($info->is_default)
                              <span class="badge badge-primary">Default</span>
                            @endif
                          </td>
                          
                          <td>

                          <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fa fa-ellipsis-v"></i>
                        </button>

                              <div class="dropdown-menu" role="menu" style="">

                                 <a class="dropdown-item" href="{{ route('branch.edit',['id'=>$info['id']]) }}">
                                  <i class="fa fa-edit"></i> Update
                                </a>

                               <form id="deleteForm-{{ $info['id'] }}" action="{{ route('branch.delete', ['id'=>$info['id']]) }}" method="POST" style="display:inline;">
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

@endpush