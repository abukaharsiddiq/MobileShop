@extends('back-end.master')

@section('admin-title') {{ $name }} @endsection
@section('name') {{ $name }} @endsection
@section('title') {{ $title }} @endsection

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card">

            <div class="card-header">
                <h1 class="card-title">Create {{ $name }}</h1>
                <a href="{{ route('salesman.index') }}" class="btn btn-sm back-btn">
                    Manage {{ $name }} <i class="fa fa-arrow-right"></i>
                </a>
            </div>

            <form action="{{ route('salesman.store') }}" method="POST">
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

                        <!-- Name -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Name *</label>
                            <div class="col-sm-9">
                                <input type="text" name="name" class="form-control"
                                    value="{{ old('name') }}">
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Email *</label>
                            <div class="col-sm-9">
                                <input type="email" name="email" class="form-control"
                                    value="{{ old('email') }}">
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Phone *</label>
                            <div class="col-sm-9">
                                <input type="text" name="phone" class="form-control"
                                    value="{{ old('phone') }}">
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Address *</label>
                            <div class="col-sm-9">
                                <input type="text" name="address" class="form-control"
                                    value="{{ old('address') }}">
                            </div>
                        </div>

                        <!-- Password -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Password *</label>
                            <div class="col-sm-9">
                                <input type="password" name="password" class="form-control"
                                    autocomplete="new-password" placeholder="Minimum 8 characters">
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Confirm Password *</label>
                            <div class="col-sm-9">
                                <input type="password" name="password_confirmation" class="form-control"
                                    autocomplete="new-password">
                            </div>
                        </div>

                        <!-- Branch -->
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label text-end">Branch</label>
                            <div class="col-sm-9">
                                <select name="branch_id" class="form-control">
                                    <option value="">Default branch</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-success">
                        <i class="fa fa-plus"></i> Create
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>
@endsection
