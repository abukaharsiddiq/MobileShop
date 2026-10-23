@extends('back-end.master')

@section('admin-title') {{ $name }} @endsection
@section('name') {{ $name }} @endsection
@section('title') {{ $title }} @endsection

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card">

            <div class="card-header">
                <h1 class="card-title">{{ $name }}</h1>
            </div>

            <form action="{{ route('profile.change.password.updated') }}" method="POST">
                @csrf

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

                        <!-- Current Password -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Current Password *</label>
                            <div class="col-sm-9">
                                <input type="password" name="current_password" class="form-control"
                                    autocomplete="current-password">
                            </div>
                        </div>

                        <!-- New Password -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">New Password *</label>
                            <div class="col-sm-9">
                                <input type="password" name="new_password" class="form-control"
                                    autocomplete="new-password" placeholder="Minimum 8 characters">
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Confirm New Password *</label>
                            <div class="col-sm-9">
                                <input type="password" name="confirm_password" class="form-control"
                                    autocomplete="new-password">
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Update Password
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>
@endsection
