@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <a href="{{ route('backups.index') }}" class="btn btn-sm btn-light mb-3">&larr; Backups</a>
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card card-outline card-warning">
                <div class="card-header"><h5 class="m-0"><i class="fas fa-undo text-warning"></i> Restore this backup?</h5></div>
                <div class="card-body" style="line-height: 2">
                    <table class="table table-sm mb-3">
                        <tr><th style="width: 35%">Backup</th><td>{{ ($backup->completed_at ?? $backup->started_at)?->format('d M Y, h:i A') }}
                            <span class="badge badge-{{ $backup->typeColor() }}">{{ $backup->typeLabel() }}</span></td></tr>
                        @if($backup->note)<tr><th>Note</th><td>{{ $backup->note }}</td></tr>@endif
                        <tr><th>File</th><td class="text-break">{{ $backup->original_name ?? $backup->filename }} ({{ $backup->sizeLabel() }})</td></tr>
                        <tr><th>Tables</th><td>{{ $backup->table_count ?? '-' }}{{ $backup->rows() !== null ? ' · ' . number_format($backup->rows()) . ' rows' : '' }}</td></tr>
                        <tr><th>Check</th><td>
                            @if($backup->verify_status === 'passed')<span class="badge badge-success">passed</span> {{ $backup->verified_at->diffForHumans() }}
                            @elseif($backup->verify_status)<span class="badge badge-{{ $backup->verify_status === 'failed' ? 'danger' : 'warning' }}">{{ $backup->verify_status }}</span> {{ $backup->verify_note }}
                            @else <span class="text-muted">যাচাই হয়নি — আগে তালিকা থেকে Check চালানো ভালো।</span>@endif</td></tr>
                        <tr><th>Current database</th><td>{{ $stats['database'] }} · {{ \App\Models\Backup::bytes($stats['db_size']) }} · {{ $stats['tables'] }} tables</td></tr>
                    </table>

                    <div class="alert alert-warning" style="color: inherit">
                        <strong>যা হবে:</strong>
                        <ol class="pl-3 mb-0">
                            <li>বর্তমান database-এর একটি <strong>"Before restore"</strong> backup নেওয়া হবে।</li>
                            <li>সব table এই backup-এর সময়ের অবস্থায় ফিরে যাবে। <strong>এর পরে করা বিক্রি, কেনা, পেমেন্ট ও অন্য সব entry মুছে যাবে।</strong></li>
                            <li>Login session, queue আর backup তালিকা বদলাবে না। এই backup-এ আপনার user না থাকলে আবার login করতে হবে।</li>
                            <li>এই সময় অন্য কেউ যেন software-এ কাজ না করে।</li>
                        </ol>
                    </div>

                    <form method="POST" action="{{ route('backups.restore', $backup->id) }}" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').innerHTML = 'Restoring... পেজ বন্ধ করবেন না'; return true">
                        @csrf
                        <div class="form-group">
                            <label>নিশ্চিত করতে বড় হাতের অক্ষরে <code>RESTORE</code> লিখুন</label>
                            <input type="text" name="confirm" class="form-control @error('confirm') is-invalid @enderror" autocomplete="off" required>
                            @error('confirm')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button class="btn btn-warning btn-block"><i class="fas fa-undo"></i> Restore this backup</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
