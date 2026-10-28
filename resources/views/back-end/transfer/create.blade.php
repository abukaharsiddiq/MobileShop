@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <form method="POST" action="{{ route('transfer.store') }}">
        @csrf
        <input type="hidden" name="request_token" value="{{ old('request_token', (string) \Illuminate\Support\Str::uuid()) }}">
        <div class="card">
            <div class="card-header"><h6 class="m-0">Request stock transfer</h6></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>From (source) <span class="text-danger">*</span></label>
                        <select name="from_branch_id" id="from" class="form-control" required>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected(old('from_branch_id') == $b->id)>{{ $b->name }}{{ $b->isWarehouse() ? ' (warehouse)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>To (destination) <span class="text-danger">*</span></label>
                        <select name="to_branch_id" class="form-control" required>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected(old('to_branch_id', in_array($b->id, $mine) ? $b->id : null) == $b->id)>{{ $b->name }}{{ $b->isWarehouse() ? ' (warehouse)' : '' }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">One of the two must be your branch.</small>
                    </div>
                    <div class="form-group col-md-4"><label>Note</label><input type="text" name="note" class="form-control" value="{{ old('note') }}"></div>
                </div>

                <table class="table table-sm table-bordered" id="lines">
                    <thead class="thead-light"><tr><th>Product / variant</th><th style="width:140px">Available at source</th><th style="width:120px">Quantity</th><th style="width:50px"></th></tr></thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addLine"><i class="fa fa-plus"></i> Add product</button>
                <hr>
                <button class="btn btn-primary">Send request</button>
                <small class="text-muted ml-2">Stock moves only when the source branch dispatches an approved transfer.</small>
            </div>
        </div>
    </form>
</div>
@endsection

@push('admin-scripts')
<script>
    const options = @json($stocks->map(fn ($s) => ['id' => $s->id, 'label' => $s->variantLabel()])->values());
    let row = 0;

    function addLine(stockId, qty) {
        let i = row++;
        let select = $('<select class="form-control form-control-sm stock-select" required>').attr('name', 'items[' + i + '][main_stock_id]')
            .append(new Option('-- select --', ''));
        options.forEach(o => select.append(new Option(o.label, o.id, false, String(o.id) === String(stockId))));
        let tr = $('<tr>')
            .append($('<td>').append(select))
            .append($('<td class="available text-muted">-</td>'))
            .append($('<td>').append($('<input type="number" min="1" class="form-control form-control-sm" required>').attr('name', 'items[' + i + '][qty]').val(qty || 1)))
            .append($('<td>').append($('<button type="button" class="btn btn-sm remove"><i class="fa fa-trash-alt"></i></button>')));
        $('#lines tbody').append(tr);
        select.select2({ width: '100%' }).on('change', () => loadAvailable(tr));
        if (stockId) loadAvailable(tr);
    }

    function loadAvailable(tr) {
        let stock = tr.find('.stock-select').val();
        if (!stock) return tr.find('.available').text('-');
        $.get("{{ route('transfer.available') }}", { branch_id: $('#from').val(), main_stock_id: stock })
            .done(res => tr.find('.available').text(res.available))
            .fail(() => tr.find('.available').text('?'));
    }

    $('#addLine').on('click', () => addLine());
    $(document).on('click', '.remove', function () { $(this).closest('tr').remove(); });
    $('#from').on('change', () => $('#lines tbody tr').each(function () { loadAvailable($(this)); }));

    @php $oldItems = old('items', []); @endphp
    @forelse($oldItems as $item)
        addLine(@json($item['main_stock_id'] ?? null), @json($item['qty'] ?? 1));
    @empty
        addLine();
    @endforelse
</script>
@endpush
