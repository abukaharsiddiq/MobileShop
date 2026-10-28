
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

                     @php $current = 'branch'; $currentManager = null; @endphp
                    <div class="form-group row">
                      <label class="col-sm-3 text-end control-label col-form-label">Type</label>
                      <div class="col-sm-9">
                        <select name="branch_type" class="form-control">
                          @foreach(\App\Models\Branch::TYPES as $key => $label)
                            <option value="{{ $key }}" @selected(old('branch_type', $current ?? 'branch') === $key)>{{ $label }}</option>
                          @endforeach
                        </select>
                        <small class="text-muted">A warehouse keeps stock but cannot sell at the POS; transfer stock to a branch to sell it.</small>
                        @error('branch_type')<span class="text-danger d-block">{{ $message }}</span>@enderror
                      </div>
                    </div>
                    <div class="form-group row">
                      <label class="col-sm-3 text-end control-label col-form-label">Manager</label>
                      <div class="col-sm-9">
                        <select name="manager_id" class="form-control">
                          <option value="">--</option>
                          @foreach($managers as $m)
                            <option value="{{ $m->id }}" @selected((int) old('manager_id', $currentManager ?? 0) === $m->id)>{{ $m->name }} ({{ $m->role }})</option>
                          @endforeach
                        </select>
                      </div>
                    </div>

                    <div class="form-group row">
                      <label for="branch_email" class="col-sm-3 text-end control-label col-form-label">Branch Email</label>
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

                     <div class="form-group row">
                      <label for="branch_code" class="col-sm-3 text-end control-label col-form-label">Branch Code</label>
                      <div class="col-sm-9">
                        <input type="text" name="branch_code" class="form-control" id="branch_code"
                          value="{{ old('branch_code') }}" placeholder="e.g. MAIN, WH-1" />
                        @error('branch_code')
                          <span class="text-danger">{{ $message }}</span>
                        @enderror
                      </div>
                    </div>

                     <div class="form-group row">
                      <div class="col-sm-9 offset-sm-3">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="is_default" value="1" id="is_default" {{ old('is_default') ? 'checked' : '' }}>
                          <label class="form-check-label" for="is_default">Default branch (used when a user has no branch)</label>
                        </div>
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