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

@section('admin-content')
 <div class="row">
 	<div class="col-md-12">
 		<div class="card">
          <div class="card-header">
            <h1 class="card-title">Update {{ $name }}</h1>
             <a href="{{ route('customer.index') }}" class="btn btn-sm back-btn">
                Manage {{ $name }} <i class="fa fa-arrow-right"></i>
             </a>
          </div>
          <form class="form-horizontal" action="{{ route('customer.update', $customer->id) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="card-body">
              <div class="col-md-8">
                @include('back-end.customer._form', ['showOpening' => auth()->user()->can('update customer opening balance')])
              </div>
            </div>
            <div class="border-top">
              <div class="card-footer">
                <button type="submit" class="btn"><i class="nav-icon fa fa-paper-plane"></i> Update</button>
              </div>
            </div>
          </form>
        </div>
 	</div>
 </div>
@endsection
