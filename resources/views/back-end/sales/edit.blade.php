@extends('back-end.master')

@section('admin-title') {{ $name }} @endsection
@section('name') {{ $name }} @endsection
@section('title') {{ $title }} @endsection

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card">

            <div class="card-header">
                <h1 class="card-title">Edit {{ $name }}</h1>
                <a href="{{ route('salesman.index') }}" class="btn btn-sm back-btn">
                    Manage {{ $name }} <i class="fa fa-arrow-right"></i>
                </a>
            </div>

            <form action="{{ route('salesman.update',$employee->id) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="card-body">
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="col-md-8">

                        <!-- Name -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Name *</label>
                            <div class="col-sm-9">
                                <input type="text" name="name" class="form-control"
                                    value="{{ old('name',$employee->name) }}">
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Email *</label>
                            <div class="col-sm-9">
                                <input type="email" name="email" class="form-control"
                                    value="{{ old('email',$employee->email) }}">
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Phone *</label>
                            <div class="col-sm-9">
                                <input type="text" name="phone" class="form-control"
                                    value="{{ old('phone',$employee->phone) }}">
                            </div>
                        </div>

                          <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Address *</label>
                            <div class="col-sm-9">
                                <input type="text" name="address" class="form-control"
                                    value="{{ old('address',$employee->address) }}">
                            </div>
                        </div>

                        <!-- New Password (optional) -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">New Password</label>
                            <div class="col-sm-9">
                                <input type="password" name="password" class="form-control"
                                    autocomplete="new-password" placeholder="Leave blank to keep current password">
                            </div>
                        </div>

                        <!-- Confirm New Password -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Confirm New Password</label>
                            <div class="col-sm-9">
                                <input type="password" name="password_confirmation" class="form-control"
                                    autocomplete="new-password">
                            </div>
                        </div>


                        <!-- Role -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Role *</label>
                            <div class="col-sm-9">
                                <select name="role" class="form-control">
                                    @foreach($roles as $value => $label)
                                        <option value="{{ $value }}" {{ old('role', $employee->role) == $value ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">Changing the role does not change the user's permissions. Use Assign Permission &rarr; Load role defaults.</small>
                            </div>
                        </div>

                        <!-- Branch -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Branch</label>
                            <div class="col-sm-9">
                                <select name="branch_id" class="form-control">
                                    <option value="">Not assigned (all branches)</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" {{ old('branch_id', $employee->branch_id) == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Status</label>
                            <div class="col-sm-9">
                                <select name="status" class="form-control">
                                    <option value="1" {{ old('status', $employee->status) == 1 ? 'selected' : '' }}>Active</option>
                                    <option value="0" {{ old('status', $employee->status) == 0 ? 'selected' : '' }}>Inactive</option>
                                </select>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Update
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>
@endsection
