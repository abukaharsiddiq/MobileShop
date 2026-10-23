@extends('back-end.master')

@section('admin-title')
    {{ $create ?? 'Create Setting' }}
@endsection

@push('admin-styles')
<style>
    /* স্টাইল এখানে যুক্ত করা হবে */
</style>
@endpush

@section('admin-content')
<div class="row">
    <div class="col-12 form-area">
        <div class="col-md-12">
            <div class="card">

                <div class="card-header">
                    <h1 class="card-title">{{ $create ?? 'Create Setting' }}</h1>
                </div>

                <form class="form-horizontal" action="{{ $action ?? '#' }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @if(!empty($method) && strtoupper($method) === 'PATCH')
                        @method('PATCH')
                    @endif

                    <div class="card-body">

                        <div class="row">
                            <div class="col-md-6">
                                <label for="name" class="control-label col-form-label">Name</label>
                                <input type="text" name="name" class="form-control" id="name" placeholder="Name" value="{{ $info->name ?? '' }}">
                            </div>

                            <div class="col-md-6">
                                <label for="email" class="control-label col-form-label">E-Mail</label>
                                <input type="text" name="email" class="form-control" id="email" placeholder="Email" value="{{ $info->email ?? '' }}">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <label for="phone" class="control-label col-form-label">Phone</label>
                                <input type="text" name="phone" class="form-control" id="phone" placeholder="Phone" value="{{ $info->phone ?? '' }}">
                            </div>

                            <div class="col-md-6">
                                <label for="address" class="control-label col-form-label">Address</label>
                                <input type="text" name="address" class="form-control" id="address" placeholder="Address" value="{{ $info->address ?? '' }}">
                            </div>
                        </div>

                        {{-- Invoice Section --}}
                        <div class="row">
                            <div class="col-md-6">
                                <label for="sale_invoice_prefix">Sale Invoice Prefix</label>
                                <input type="text" name="sale_invoice_prefix" class="form-control" value="{{ $info->sale_invoice_prefix ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="sale_invoice_number">Sale Invoice Start Number</label>
                                <input type="text" name="sale_invoice_number" class="form-control" value="{{ $info->sale_invoice_number ?? '' }}">
                            </div>
                        </div>

                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label for="purchase_invoice_prefix">Purchase Invoice Prefix</label>
                                <input type="text" name="purchase_invoice_prefix" class="form-control" value="{{ $info->purchase_invoice_prefix ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="purchase_invoice_number">Purchase Invoice Start Number</label>
                                <input type="text" name="purchase_invoice_number" class="form-control" value="{{ $info->purchase_invoice_number ?? '' }}">
                            </div>
                        </div>

                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label for="service_invoice_prefix">Service Invoice Prefix</label>
                                <input type="text" name="service_invoice_prefix" class="form-control" value="{{ $info->service_invoice_prefix ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="service_invoice_number">Service Invoice Start Number</label>
                                <input type="text" name="service_invoice_number" class="form-control" value="{{ $info->service_invoice_number ?? '' }}">
                            </div>
                        </div>

                        {{-- Office Time --}}
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label for="office_in_time">Office In Time</label>
                                <input type="text" name="office_in_time" class="form-control" value="{{ $info->office_in_time ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="office_out_time">Office Out Time</label>
                                <input type="text" name="office_out_time" class="form-control" value="{{ $info->office_out_time ?? '' }}">
                            </div>
                        </div>

                        {{-- Copyright & Developed By --}}
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label for="copyright_text">Copyright Text</label>
                                <input type="text" name="copyright_text" class="form-control" value="{{ $info->copyright_text ?? '' }}">
                            </div>
                            <div class="col-md-6">
                                <label for="developed_by">Developed By</label>
                                <input type="text" name="developed_by" class="form-control" value="{{ $info->developed_by ?? '' }}">
                            </div>
                        </div>

                        {{-- Logo --}}
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label for="logo">Logo (130*60px)</label>
                                <input type="file" name="logo" class="form-control"/>
                                @if(!empty($info->logo))
                                    <img src="{{ asset('back-end/setting/' . $info->logo) }}" style="width:100px;height:100px;margin-top:10px;">
                                @endif
                            </div>
                        </div>

                    </div>

                    <div class="border-top">
                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary float-end">
                                <i class="fa fa-paper-plane"></i> {{ $btn_name ?? 'Submit' }}
                            </button>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
@endsection
