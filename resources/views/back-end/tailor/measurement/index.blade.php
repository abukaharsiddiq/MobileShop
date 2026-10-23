@extends('back-end.master')
@section('admin-content')
<div class="container-fluid py-4">
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Your Measurement Profiles</h5>
        </div>
        <div class="card-body">
            <table class="table table-hover align-middle">
    <thead>
        <tr>
            <th>Profile Name</th>
            <th>Chest</th>
            <th>Length</th>
            <th>Hip</th>
            <th>Pant Length</th>
            <th class="text-center">Action</th>
        </tr>
    </thead>
    <tbody>
        @foreach($measurements as $m)
        <tr>
            <td class="fw-bold text-primary">{{ $m->profile_name }}</td>
            <td>{{ $m->body_chest ?? '-' }}″</td>
            <td>{{ $m->length ?? '-' }}″</td>
            <td>{{ $m->hip ?? '-' }}″</td>
            <td>{{ $m->pant_length ?? '-' }}″</td>
            <td class="text-center">

                <a href="{{ route('tailor.measurement.show', $m->id) }}" class="btn btn-sm btn-info text-white"><i class="fas fa-eye"></i></a>

                <a href="{{ route('tailor.measurement.edit', $m->id) }}" class="btn btn-sm btn-info text-white"><i class="fas fa-edit"></i></a>

                <form action="{{ route('tailor.measurement.delete', $m->id) }}" method="POST" class="d-inline">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger" onclick="return confirm('Delete this profile?')"><i class="fas fa-trash"></i></button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
        </div>
    </div>
</div>
@endsection