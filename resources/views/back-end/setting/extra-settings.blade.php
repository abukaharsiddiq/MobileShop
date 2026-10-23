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
    <div class="col-12 form-area">
        <div class="col-md-12">
            <div class="card">


      <div class="card-header">
        <h1 class="card-title">{{ $create }}</h1>
      </div>

                <form class="form-horizontal" action="{{ $action }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method($method === 'POST' ? 'POST' : 'PATCH')
                    <div class="card-body">
                        <div class="col-md-8">

                        <div class="form-group row">
                            <label for="vat" class="col-sm-3 control-label col-form-label">VAT <span class="text-danger">(%)</span> </label>
                            <div class="col-sm-9">
                                <input
                                    type="number" name="vat"
                                    class="form-control inputDisableText"
                                    id="vat"
                                    placeholder="vat"
                                    value="{{ $info->vat ?? '' }}"
                                />
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="tax" class="col-sm-3 control-label col-form-label">TAX <span class="text-danger">(%)</span></label>
                            <div class="col-sm-9">
                                <input
                                    type="number" name="tax"
                                    class="form-control inputDisableText"
                                    id="tax"
                                    placeholder="Tax"
                                    value="{{ $info->tax ?? '' }}"
                                />
                            </div>
                        </div>

                        <div class="form-group row d-none">
                            <label for="discount" class="col-sm-3 control-label col-form-label">Discount <span class="text-danger">(%)</span></label>
                            <div class="col-sm-9">
                                <input
                                    type="number" name="discount"
                                    class="form-control inputDisableText"
                                    id="discount"
                                    placeholder="Discount"
                                    value="{{ $info->discount ?? '' }}"
                                />
                            </div>
                        </div>

                   <!--      <div class="form-group row">
                            <label for="shifment_cost" class="col-sm-3 control-label col-form-label">Shifment Cost <span class="text-danger">(tk)</span></label>
                            <div class="col-sm-9">
                                <input
                                    type="number" name="shifment_cost"
                                    class="form-control"
                                    id="shifment_cost"
                                    placeholder="Shiftment Cost"
                                    value="{{ $info->shifment_cost ?? '' }}"
                                />
                            </div>
                        </div>
                         -->
                    </div>
                    </div>
                    <div class="border-top">
                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary float-end">
                                <i class="fa fa-paper-plane"></i> {{ $btn_name }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
