{{-- Supplier profile field (create/edit). $info = null hole notun supplier --}}
@php
    $field = fn ($key) => old($key, $info->{$key} ?? '');
    $rows = [
        ['name', 'Name', 'text', true],
        ['contact_person', 'Contact Person', 'text', false],
        ['phone', 'Phone No', 'text', true],
        ['email', 'Email', 'email', false],
        ['address', 'Address', 'text', false],
    ];
@endphp

@foreach($rows as [$key, $label, $type, $required])
<div class="form-group row">
  <label for="{{ $key }}" class="col-sm-3 text-end control-label col-form-label">{{ $label }} @if($required)<span class="text-danger">*</span>@endif</label>
  <div class="col-sm-9">
    <input type="{{ $type }}" name="{{ $key }}" id="{{ $key }}" class="form-control" value="{{ $field($key) }}" placeholder="{{ $label }}" @if($required) required @endif/>
    @error($key)
    <span class="text-danger">{{ $message }}</span>
    @enderror
  </div>
</div>
@endforeach

<div class="form-group row">
  <label for="note" class="col-sm-3 text-end control-label col-form-label">Note</label>
  <div class="col-sm-9">
    <textarea name="note" id="note" class="form-control" rows="2" placeholder="Bank details, terms, etc.">{{ $field('note') }}</textarea>
    @error('note')
    <span class="text-danger">{{ $message }}</span>
    @enderror
  </div>
</div>

@if($showOpening)
@php
    $opening = (float) ($info->opening_balance ?? 0);
    $openingType = old('opening_balance_type', $opening < 0 ? 'advance' : 'payable');
    $openingAmount = old('opening_balance', abs($opening));
@endphp
<div class="form-group row">
  <label for="opening_balance" class="col-sm-3 text-end control-label col-form-label">Opening Balance</label>
  <div class="col-sm-5">
    <input type="number" step="0.01" min="0" name="opening_balance" id="opening_balance" class="form-control" value="{{ $openingAmount }}" placeholder="0.00"/>
    @error('opening_balance')
    <span class="text-danger">{{ $message }}</span>
    @enderror
  </div>
  <div class="col-sm-4">
    <select name="opening_balance_type" class="form-control">
      <option value="payable" @selected($openingType === 'payable')>Payable (we owe supplier)</option>
      <option value="advance" @selected($openingType === 'advance')>Advance (supplier holds our money)</option>
    </select>
  </div>
</div>
@if($info)
<div class="form-group row">
  <label for="opening_reason" class="col-sm-3 text-end control-label col-form-label">Reason for change</label>
  <div class="col-sm-9">
    <input type="text" name="opening_reason" id="opening_reason" class="form-control" value="{{ old('opening_reason') }}" maxlength="255" placeholder="Required only if you change the opening balance"/>
    <small class="text-muted">The opening balance line in the ledger is updated and the change is recorded in the audit log.</small>
    @error('opening_reason')
    <br><span class="text-danger">{{ $message }}</span>
    @enderror
  </div>
</div>
@endif
@elseif($info)
<div class="form-group row">
  <label class="col-sm-3 text-end control-label col-form-label">Opening Balance</label>
  <div class="col-sm-9">
    <p class="form-control-plaintext">
      {{ number_format(abs($info->opening_balance), 2) }} {{ $info->opening_balance < 0 ? '(Advance)' : '(Payable)' }}
      <small class="text-muted">- only users with the "update supplier opening balance" permission can change this.</small>
    </p>
  </div>
</div>
@endif
