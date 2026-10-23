
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
	
</style>
@endpush

@section('admin-content')
 <div class="row">
 	<div class="col-md-12">
 		<div class="card">
       <div class="card-header">
                      <h1 class="card-title">Create {{ $name }}</h1>
                      <a href="{{ route('permission.index') }}" class="btn btn-sm back-btn">
                         Manage {{ $name }} <i class="fa fa-arrow-right"></i>
                      </a>
                    </div>
                <form class="form-horizontal" action="{{ route('permission.store') }}" method="POST">
                	@csrf
                  <div class="card-body">
                   
                   <div class="col-md-8">
                    
                     <div class="form-group row">
                      <label for="group_name" class="col-sm-3 text-end control-label col-form-label">Group Name <span class="text-danger">*</span> </label>
                      <div class="col-sm-9">
                        <input
                          type="text" name="group_name"
                          class="form-control"
                          id="group_name" value="{{ old('group_name') }}"
                          placeholder="Group Name"
                        />
                        @error('group_name')
                          <span class="text-danger">{{ $message }}</span>
                          @enderror
                      </div>
                    </div>

                     <div class="form-group row">
                      <label for="name" class="col-sm-3 text-end control-label col-form-label">Permission Name <span class="text-danger">*</span> </label>
                      <div class="col-sm-9">
                        <input
                          type="text" name="name"
                          class="form-control"
                          id="name" value="{{ old('name') }}"
                          placeholder="Permission Name"
                        />
                        @error('name')
                          <span class="text-danger">{{ $message }}</span>
                          @enderror
                      </div>
                    </div>

                   </div>
                   
                  </div>
                  <div class="border-top">
                    <div class="card-footer">
                      <button type="submit" class="btn btn-primary">
                         <i class="fa fa-paper-plane"></i> Submit
                      </button>
                    </div>
                  </div>
                </form>
              </div>
 	</div>
 </div>
@endsection