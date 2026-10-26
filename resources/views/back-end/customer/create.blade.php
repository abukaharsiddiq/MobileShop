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
            <h1 class="card-title">Create {{ $name }}</h1>
             <a href="{{ route('customer.index') }}" class="btn btn-sm back-btn">
                Manage {{ $name }} <i class="fa fa-arrow-right"></i>
             </a>
          </div>
          <form class="form-horizontal" action="{{ route('customer.store') }}" method="POST">
          	@csrf
            <div class="card-body">
              <div class="col-md-8">
                @include('back-end.customer._form', ['customer' => null, 'showOpening' => true])
              </div>
            </div>
            <div class="border-top">
              <div class="card-footer">
                <button type="submit" class="btn"><i class="nav-icon fa fa-paper-plane"></i> Submit</button>
              </div>
            </div>
          </form>
        </div>
 	</div>
 </div>
@endsection
