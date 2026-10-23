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
    /* আপনার কাস্টম স্টাইল এখানে যোগ করতে পারেন */
</style>
@endpush

@section('admin-content')
 <div class="row">
    <div class="col-md-12">
        <div class="card">
          <div class="card-header">
            <h1 class="card-title">Edit {{ $name }} </h1>
             <a href="{{ route('customer.index') }}" class="btn btn-sm back-btn">
                Manage {{ $name }} <i class="fa fa-arrow-right"></i>
             </a>
          </div>
          
          <form class="form-horizontal" action="{{ route('customer.update', $customer->id) }}" method="POST">
            @csrf
            @method('PUT')
            
            <div class="card-body">
              <div class="col-md-8">

                 <div class="form-group row">
                      <label for="fname" class="col-sm-3 text-end control-label col-form-label">Name <span class="text-danger">*</span></label>
                      <div class="col-sm-9">
                        <input
                          type="text" name="name"
                          class="form-control"
                          id="fname" value="{{ old('name', $customer->name) }}"
                          placeholder="Name"
                        />
                         @error('name')
                        <span class="text-danger">{{ $message }}</span>
                        @enderror
                      </div>
                    </div>

                    <div class="form-group row">
                      <label for="lname" class="col-sm-3 text-end control-label col-form-label">Phone No <span class="text-danger">*</span></label>
                      <div class="col-sm-9">
                        <input
                          type="text" name="phone"
                          class="form-control"
                          id="lname" value="{{ old('phone', $customer->phone) }}"
                          placeholder="Phone No"
                        />
                         @error('phone')
                        <span class="text-danger">{{ $message }}</span>
                        @enderror
                      </div>
                    </div>

                    <div class="form-group row">
                      <label for="email1" class="col-sm-3 text-end control-label col-form-label">Address</label>
                      <div class="col-sm-9">
                        <input
                          type="text" name="address"
                          class="form-control"
                          id="email1" value="{{ old('address', $customer->address) }}"
                          placeholder="Address"
                        />
                         @error('address')
                        <span class="text-danger">{{ $message }}</span>
                        @enderror
                      </div>
                    </div>

              </div>
            </div>

            <div class="border-top">
              <div class="card-footer">
                <button type="submit" class="btn">
                   <i class="nav-icon fa fa-save"></i> Update {{ $name }}
                </button>
              </div>
            </div>

          </form>
        </div>
    </div>
 </div>
@endsection