@extends('back-end.master')
@section('admin-title', $name)

@section('admin-content')
@php
    $type = old('type', $account->type);
    $locked = $account->exists && ($account->is_system || $account->lines()->exists());
@endphp
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0">{{ $name }}</h5>
                    <a href="{{ route('finance.accounts.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Accounts</a>
                </div>
                <form method="POST" action="{{ $account->exists ? route('finance.accounts.update', $account->id) : route('finance.accounts.store') }}">
                    @csrf
                    @if($account->exists) @method('PUT') @endif
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label>Code</label>
                                <input type="text" name="code" class="form-control" value="{{ old('code', $account->code) }}" maxlength="20" placeholder="Auto">
                            </div>
                            <div class="form-group col-md-9">
                                <label>Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="{{ old('name', $account->name) }}" required maxlength="255">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Type <span class="text-danger">*</span></label>
                                <select name="type" id="type" class="form-control" @disabled($locked)>
                                    @foreach(\App\Models\Account::types() as $key => $label)
                                        <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @if($locked)<input type="hidden" name="type" value="{{ $account->type }}">@endif
                            </div>
                            <div class="form-group col-md-6">
                                <label>Kind <span class="text-danger">*</span></label>
                                <select name="subtype" id="subtype" class="form-control" @disabled($locked)>
                                    @if($locked)
                                        <option value="{{ $account->subtype }}" selected>{{ ucwords(str_replace('_', ' ', $account->subtype)) }}</option>
                                    @else
                                        @foreach($subtypes as $group => $options)
                                            @foreach($options as $key => $label)
                                                <option value="{{ $key }}" data-type="{{ $group }}" @selected(old('subtype', $account->subtype) === $key)>{{ $label }}</option>
                                            @endforeach
                                        @endforeach
                                    @endif
                                </select>
                                @if($locked)<input type="hidden" name="subtype" value="{{ $account->subtype }}">@endif
                                @error('subtype')<span class="text-danger small">{{ $message }}</span>@enderror
                            </div>
                        </div>

                        <div class="js-money">
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>Payment method</label>
                                    <select name="payment_method" class="form-control">
                                        <option value="">-- none --</option>
                                        @foreach(array_keys(\App\Models\SalePayment::methods()) as $method)
                                            @continue($method === 'Store Credit')
                                            <option value="{{ $method }}" @selected(old('payment_method', $account->payment_method) === $method)>{{ $method }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">Payments made with this method post to this account.</small>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Bank / provider</label>
                                    <input type="text" name="bank_name" class="form-control" value="{{ old('bank_name', $account->bank_name) }}" maxlength="255">
                                </div>
                                <div class="form-group col-md-4">
                                    <label>Account / wallet no.</label>
                                    <input type="text" name="account_number" class="form-control" value="{{ old('account_number', $account->account_number) }}" maxlength="255">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Branch</label>
                                <select name="branch_id" class="form-control">
                                    <option value="">All branches</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected((int) old('branch_id', $account->branch_id) === $branch->id)>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @unless($account->exists)
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label>Opening balance</label>
                                        <input type="number" step="0.01" name="opening_balance" class="form-control" value="{{ old('opening_balance', 0) }}">
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label>Opening date</label>
                                        <input type="date" name="opening_date" class="form-control" value="{{ old('opening_date', date('Y-m-d')) }}">
                                    </div>
                                </div>
                            @endunless
                        </div>

                        <div class="form-group">
                            <label>Note</label>
                            <textarea name="note" class="form-control" rows="2">{{ old('note', $account->note) }}</textarea>
                        </div>
                        <div class="form-group">
                            <input type="hidden" name="status" value="0">
                            <label><input type="checkbox" name="status" value="1" @checked(old('status', $account->status ?? 1)) @disabled($account->is_system)> Active</label>
                            @if($account->is_system)<input type="hidden" name="status" value="1">@endif
                        </div>
                    </div>
                    <div class="card-footer"><button class="btn btn-success"><i class="fa fa-check"></i> Save</button></div>
                </form>
            </div>
        </div>

        @if($account->exists && $account->isMoney())
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Opening balance</h6></div>
                    <form method="POST" action="{{ route('finance.accounts.opening', $account->id) }}" onsubmit="return confirm('Save opening balance? An earlier opening balance is reversed, not deleted.')">
                        @csrf
                        <div class="card-body">
                            <div class="form-group">
                                <label>Amount on the opening date</label>
                                <input type="number" step="0.01" name="opening_balance" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Opening date</label>
                                <input type="date" name="opening_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                            </div>
                            <small class="text-muted">Use this once for cash in hand or the bank balance when you start using the books.</small>
                        </div>
                        <div class="card-footer"><button class="btn btn-primary btn-sm">Save opening balance</button></div>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(function () {
        function refresh() {
            const type = $('#type').val();
            $('#subtype option[data-type]').each(function () {
                $(this).toggle($(this).data('type') === type);
            });
            if ($('#subtype option:selected').data('type') && $('#subtype option:selected').data('type') !== type) {
                $('#subtype').val($('#subtype option[data-type="' + type + '"]').first().val());
            }
            $('.js-money').toggle(['cash', 'bank', 'mobile_banking'].includes($('#subtype').val()));
        }
        $('#type, #subtype').on('change', refresh);
        refresh();
    });
</script>
@endpush
