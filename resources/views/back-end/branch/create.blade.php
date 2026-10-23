
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
                      <a href="{{ route('branch.index') }}" class="btn btn-sm back-btn">
                         Manage {{ $name }} <i class="fa fa-arrow-right"></i>
                      </a>
                    </div>
                <form class="form-horizontal" action="{{ route('branch.store') }}" method="POST">
                	@csrf
                  <div class="card-body">
                   
                   <div class="col-md-8">
                    
                     <div class="form-group row">
                      <label for="fname" class="col-sm-3 text-end control-label col-form-label">Branch Name <span class="text-danger">*</span> </label>
                      <div class="col-sm-9">
                        <input
                          type="text" name="branch_name"
                          class="form-control"
                          id="fname" value="{{ old('branch_name') }}"
                          placeholder="Branch Name"
                        />
                        @error('branch_name')
                          <span class="text-danger">{{ $message }}</span>
                          @enderror
                      </div>
                    </div>

                     <div class="form-group row">
                      <label for="branch_email" class="col-sm-3 text-end control-label col-form-label">Branch Email <span class="text-danger">*</span> </label>
                      <div class="col-sm-9">
                        <input
                          type="text" name="branch_email"
                          class="form-control"
                          id="branch_email" value="{{ old('branch_email') }}"
                          placeholder="Branch Email"
                        />
                        @error('branch_email')
                          <span class="text-danger">{{ $message }}</span>
                          @enderror
                      </div>
                    </div>

                      <div class="form-group row">
                      <label for="branch_phone" class="col-sm-3 text-end control-label col-form-label">Branch Phone <span class="text-danger">*</span> </label>
                      <div class="col-sm-9">
                        <input
                          type="text" name="branch_phone"
                          class="form-control"
                          id="branch_phone" value="{{ old('branch_phone') }}"
                          placeholder="Branch Phone"
                        />
                        @error('branch_phone')
                          <span class="text-danger">{{ $message }}</span>
                          @enderror
                      </div>
                    </div>

                     <div class="form-group row">
                      <label for="branch_address" class="col-sm-3 text-end control-label col-form-label">Branch Address <span class="text-danger">*</span> </label>
                      <div class="col-sm-9">
                        <input
                          type="text" name="branch_address"
                          class="form-control"
                          id="branch_address" value="{{ old('branch_address') }}"
                          placeholder="Branch Phone"
                        />
                        @error('branch_address')
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