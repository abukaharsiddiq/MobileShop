{{-- Posted entry batil (ulta entry); muchhe na. $action = route, $label = button text --}}
@can('reverse transaction')
<form action="{{ $action }}" method="POST" class="form-inline d-print-none" onsubmit="return confirm('Reverse this entry? A reversing entry will be posted; the original stays in the books.')">
    @csrf
    @isset($method) @method($method) @endisset
    <input type="text" name="{{ $field ?? 'reason' }}" class="form-control form-control-sm mr-1" placeholder="Reason" required maxlength="255" style="width:130px">
    <button class="btn btn-outline-danger btn-sm"><i class="fa fa-undo"></i> {{ $label ?? 'Reverse' }}</button>
</form>
@endcan
