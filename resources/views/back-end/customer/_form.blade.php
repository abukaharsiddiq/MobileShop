{{-- Customer profile field (create/edit). $customer = null hole notun --}}
@php
    $field = fn ($key, $default = '') => old($key, $customer->{$key} ?? $default);
@endphp

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="form-group row">
  <label for="name" class="col-sm-3 text-end control-label col-form-label">Name <span class="text-danger">*</span></label>
  <div class="col-sm-9"><input type="text" name="name" id="name" class="form-control" value="{{ $field('name') }}" required maxlength="255"></div>
</div>

<div class="form-group row">
  <label for="phone" class="col-sm-3 text-end control-label col-form-label">Phone No <span class="text-danger">*</span></label>
  <div class="col-sm-9">
    <input type="text" name="phone" id="phone" class="form-control js-phone" value="{{ $field('phone') }}" required maxlength="20" placeholder="01XXXXXXXXX">
    <small class="js-phone-warning text-danger d-block"></small>
  </div>
</div>

<div class="form-group row">
  <label for="alt_phone" class="col-sm-3 text-end control-label col-form-label">Alternate Phone</label>
  <div class="col-sm-9">
    <input type="text" name="alt_phone" id="alt_phone" class="form-control js-phone" value="{{ $field('alt_phone') }}" maxlength="20">
    <small class="js-phone-warning text-danger d-block"></small>
  </div>
</div>

<div class="form-group row">
  <label for="customer_type" class="col-sm-3 text-end control-label col-form-label">Customer Type</label>
  <div class="col-sm-9">
    <select name="customer_type" id="customer_type" class="form-control">
      @foreach(\App\Models\Customer::TYPES as $key => $label)
        <option value="{{ $key }}" @selected($field('customer_type', 'retail') === $key)>{{ $label }}</option>
      @endforeach
    </select>
  </div>
</div>

<div class="form-group row">
  <label for="email" class="col-sm-3 text-end control-label col-form-label">Email</label>
  <div class="col-sm-9"><input type="email" name="email" id="email" class="form-control" value="{{ $field('email') }}" maxlength="255"></div>
</div>

<div class="form-group row">
  <label for="nid_no" class="col-sm-3 text-end control-label col-form-label">NID No</label>
  <div class="col-sm-9"><input type="text" name="nid_no" id="nid_no" class="form-control" value="{{ $field('nid_no') }}" maxlength="30"></div>
</div>

<div class="form-group row">
  <label for="address" class="col-sm-3 text-end control-label col-form-label">Address</label>
  <div class="col-sm-9"><input type="text" name="address" id="address" class="form-control" value="{{ $field('address') }}" maxlength="1000"></div>
</div>

<div class="form-group row">
  <label class="col-sm-3 text-end control-label col-form-label">Messages</label>
  <div class="col-sm-9 pt-2">
    <input type="hidden" name="consent_form" value="1">
    <label class="mr-3 font-weight-normal"><input type="checkbox" name="notify_sms" value="1" @checked(old('notify_sms', $customer->notify_sms ?? true))> SMS (receipts, payments, reminders)</label>
    <label class="font-weight-normal"><input type="checkbox" name="notify_whatsapp" value="1" @checked(old('notify_whatsapp', $customer->notify_whatsapp ?? false))> WhatsApp (only if the customer agreed)</label>
  </div>
</div>

<div class="form-group row">
  <label for="note" class="col-sm-3 text-end control-label col-form-label">Note</label>
  <div class="col-sm-9"><textarea name="note" id="note" class="form-control" rows="2" maxlength="2000">{{ $field('note') }}</textarea></div>
</div>

@if($showOpening)
@php
    $opening = (float) ($customer->opening_balance ?? 0);
    $openingType = old('opening_balance_type', $opening < 0 ? 'advance' : 'due');
@endphp
<div class="form-group row">
  <label for="opening_balance" class="col-sm-3 text-end control-label col-form-label">Opening Balance</label>
  <div class="col-sm-5"><input type="number" step="0.01" min="0" name="opening_balance" id="opening_balance" class="form-control" value="{{ old('opening_balance', abs($opening)) }}"></div>
  <div class="col-sm-4">
    <select name="opening_balance_type" class="form-control">
      <option value="due" @selected($openingType === 'due')>Due (customer owes us)</option>
      <option value="advance" @selected($openingType === 'advance')>Advance (we hold customer's money)</option>
    </select>
  </div>
</div>
@if($customer)
<div class="form-group row">
  <label for="opening_reason" class="col-sm-3 text-end control-label col-form-label">Reason for change</label>
  <div class="col-sm-9">
    <input type="text" name="opening_reason" id="opening_reason" class="form-control" value="{{ old('opening_reason') }}" maxlength="255" placeholder="Required only if you change the opening balance">
  </div>
</div>
@endif
@elseif($customer)
<div class="form-group row">
  <label class="col-sm-3 text-end control-label col-form-label">Opening Balance</label>
  <div class="col-sm-9"><p class="form-control-plaintext">{{ number_format(abs($customer->opening_balance), 2) }} {{ $customer->opening_balance < 0 ? '(Advance)' : '(Due)' }}</p></div>
</div>
@endif

@push('admin-scripts')
<script>
    // ekoi number (jekono format e) onno customer er kina - type korar somoy dekhay
    $(document).on('blur', '.js-phone', function () {
        let input = $(this), warning = input.siblings('.js-phone-warning').text('');
        if (!input.val().trim()) return;
        $.get("{{ route('customer.check_phone') }}", { phone: input.val(), except_id: "{{ $customer->id ?? '' }}" }, function (res) {
            if (res.duplicate) {
                warning.text('Already used by: ' + res.customers.map(c => c.name + ' (' + c.phone + ', ID #' + c.id + ')').join(', '));
            }
        });
    });
</script>
@endpush
