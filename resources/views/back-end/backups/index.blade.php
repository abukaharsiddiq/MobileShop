@extends('back-end.master')
@section('admin-title', $title)

@push('admin-styles')
<style>
    .bk-stat { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; box-shadow: var(--shadow-sm); padding: 16px 18px; display: flex; gap: 14px; align-items: center; height: 100%; }
    .bk-stat .ic { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex: none; }
    .bk-stat .lb { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); font-weight: 600; }
    .bk-stat .vl { font-size: 1.35rem; font-weight: 750; color: var(--ink); line-height: 1.2; }
    .bk-stat .sb { font-size: .75rem; color: var(--muted); }
    .ic-primary { background: var(--primary-50, #eef2ff); color: var(--primary); }
    .ic-danger { background: var(--danger-50); color: var(--danger); }
    .ic-success { background: var(--success-50); color: var(--success); }
    .ic-neutral { background: var(--neutral-50); color: var(--neutral-ink); }
    .ic-info { background: var(--info-50); color: var(--info); }
    .bk-hint { line-height: 2; color: var(--muted); }
    .bk-help { line-height: 2; }
    .bk-table td { vertical-align: middle; }
    .bk-empty { padding: 48px 16px; text-align: center; color: var(--muted); }
    .bk-empty i { font-size: 30px; opacity: .5; display: block; margin-bottom: 10px; }
</style>
@endpush

@section('admin-content')
@php
    $last = $stats['last'];
    $verifyBadge = ['passed' => 'success', 'warning' => 'warning', 'failed' => 'danger'];
@endphp
<div class="container-fluid">

    {{-- ---- obostha ---- --}}
    <div class="row">
        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="bk-stat"><div class="ic ic-primary"><i class="fas fa-database"></i></div>
                <div><div class="lb">Database</div><div class="vl">{{ \App\Models\Backup::bytes($stats['db_size']) }}</div>
                    <div class="sb">{{ $stats['database'] }} &middot; {{ $stats['tables'] }} tables</div></div></div>
        </div>
        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="bk-stat"><div class="ic {{ $last && $last->completed_at->gt(now()->subDay()) ? 'ic-success' : 'ic-danger' }}"><i class="far fa-clock"></i></div>
                <div><div class="lb">Last backup</div><div class="vl">{{ $last ? $last->completed_at->diffForHumans(null, true) . ' ago' : 'Never' }}</div>
                    <div class="sb">{{ $last ? $last->completed_at->format('d M Y, h:i A') . ' · ' . $last->typeLabel() : 'এখনই একটি backup নিন' }}</div></div></div>
        </div>
        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="bk-stat"><div class="ic {{ $schedule->enabled ? 'ic-success' : 'ic-neutral' }}"><i class="far fa-calendar-check"></i></div>
                <div><div class="lb">Automatic</div><div class="vl">{{ $schedule->enabled ? 'On' : 'Off' }}</div>
                    <div class="sb">{{ $schedule->enabled ? $schedule->describe() . ' · next ' . $schedule->nextSlot()->format('d M h:i A') : 'নিজে থেকে backup বন্ধ' }}</div></div></div>
        </div>
        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="bk-stat"><div class="ic ic-info"><i class="fas fa-archive"></i></div>
                <div><div class="lb">Stored backups</div><div class="vl">{{ $stats['stored'] }}</div>
                    <div class="sb">{{ \App\Models\Backup::bytes($stats['stored_size']) }} on the server{{ $encrypted ? ' · encrypted' : '' }}</div></div></div>
        </div>
    </div>

    {{-- ---- kivabe kaj kore ---- --}}
    <div class="alert alert-warning bk-hint" style="color: inherit">
        <i class="fas fa-shield-alt"></i> <strong>Backup কীভাবে কাজ করে।</strong>
        Backup হলো সব table-এর একটি compressed SQL কপি: product, বিক্রি, customer, হিসাব, setting ও user সহ।
        Login session, cache আর চলমান queue বাদ থাকে। Backup এই server-এ public ফোল্ডারের বাইরে গোপনে রাখা হয়।
        নিয়মিত download করে অন্য কোথাও (pendrive / Google Drive) একটি কপি রাখুন — একই server-এ রাখা backup সেই server নষ্ট হলে বাঁচে না।
        আপলোড করা ছবি (product image ইত্যাদি) database-এ নয়, file হিসেবে থাকে, তাই database backup-এ সেগুলো থাকে না।
        @unless($encrypted)
            <br><i class="fas fa-unlock"></i> Backup এখন <strong>encrypted নয়</strong>। <code>php artisan backup:key</code> চালিয়ে key টি <code>.env</code>-এ BACKUP_ENCRYPTION_KEY হিসেবে দিন এবং key-এর একটি কপি server-এর বাইরে রাখুন।
        @endunless
    </div>

    <div class="row">
        {{-- ---- talika ---- --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="m-0"><i class="fas fa-archive text-primary"></i> Backups</h6>
                    <small class="text-muted">Newest first</small>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover bk-table mb-0">
                        <thead><tr><th>Backup</th><th>Type</th><th>Size</th><th>Check</th><th class="text-right">Actions</th></tr></thead>
                        <tbody>
                        @forelse($backups as $b)
                            <tr>
                                <td>
                                    <strong>{{ ($b->completed_at ?? $b->started_at)?->format('d M Y, h:i A') }}</strong>
                                    @if($b->encrypted)<i class="fas fa-lock text-success ml-1" title="Encrypted - key ছাড়া খোলা যায় না"></i>@endif
                                    @if($b->note)<div>{{ $b->note }}</div>@endif
                                    <div class="text-muted" style="font-size: .75rem">{{ $b->original_name ?? $b->filename }}
                                        @if($b->creator) &middot; {{ $b->creator->name }} @endif</div>
                                    @if($b->status === 'failed')<div class="text-danger" style="font-size: .8rem">Failed: {{ $b->error }}</div>@endif
                                    @if($b->status === 'running')<div class="text-info" style="font-size: .8rem">Running...</div>@endif
                                    @if($b->restored_at)<div class="text-warning" style="font-size: .8rem">Restored {{ $b->restored_at->format('d M Y, h:i A') }}</div>@endif
                                </td>
                                <td><span class="badge badge-{{ $b->typeColor() }}">{{ $b->typeLabel() }}</span></td>
                                <td class="text-nowrap">{{ $b->size ? $b->sizeLabel() : '-' }}
                                    @if($b->table_count)<div class="text-muted" style="font-size: .75rem">{{ $b->table_count }} tables{{ $b->rows() !== null ? ' · ' . number_format($b->rows()) . ' rows' : '' }}</div>@endif</td>
                                <td>
                                    @if($b->verify_status)
                                        <span class="badge badge-{{ $verifyBadge[$b->verify_status] ?? 'light' }}" title="{{ $b->verify_note }}">{{ $b->verify_status }}</span>
                                        <div class="text-muted" style="font-size: .75rem">{{ $b->verified_at->diffForHumans() }}</div>
                                    @elseif($b->status === 'completed')
                                        <span class="text-muted" style="font-size: .8rem">যাচাই হয়নি</span>
                                    @endif
                                </td>
                                <td class="text-right text-nowrap">
                                    @if($b->exists())
                                        <a href="{{ route('backups.download', $b->id) }}" class="btn btn-sm btn-outline-secondary" title="Download (আবার password চাইবে)"><i class="fas fa-download"></i></a>
                                        <form method="POST" action="{{ route('backups.verify', $b->id) }}" class="d-inline"
                                              onsubmit="this.querySelector('button').disabled = true; return true">@csrf
                                            <button class="btn btn-sm btn-outline-primary" title="Check: আলাদা database-এ পরীক্ষামূলক restore করে মিলিয়ে দেখে"><i class="fas fa-clipboard-check"></i></button></form>
                                        <a href="{{ route('backups.restore.confirm', $b->id) }}" class="btn btn-sm btn-outline-warning" title="Restore"><i class="fas fa-undo"></i></a>
                                    @endif
                                    <form method="POST" action="{{ route('backups.destroy', $b->id) }}" class="d-inline" onsubmit="return confirm('এই backup টি স্থায়ীভাবে মুছে ফেলবেন?')">@csrf
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="bk-empty"><i class="fas fa-archive"></i>এখনো কোনো backup নেই। <strong>Back up now</strong> চেপে প্রথম backup নিন।</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($backups->hasPages())<div class="card-footer">{{ $backups->links() }}</div>@endif
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0"><i class="fas fa-undo text-warning"></i> Restore কীভাবে হয়</h6></div>
                <div class="card-body bk-help">
                    <ol class="pl-3 mb-0">
                        <li>তালিকার <i class="fas fa-undo"></i> বাটন চাপুন, password দিন, তারপর <code>RESTORE</code> লিখে নিশ্চিত করুন।</li>
                        <li>শুরুর আগে বর্তমান database-এর একটি <strong>"Before restore"</strong> backup নিজে থেকে নেওয়া হয় — ভুল হলে সেটা restore করে আগের অবস্থায় ফেরা যায়।</li>
                        <li>Login session, queue আর এই backup তালিকা বদলায় না; বাকি সব table backup-এর সময়ের অবস্থায় ফিরে যায়। Backup-এর পরে করা বিক্রি/entry মুছে যাবে।</li>
                        <li>Restore-এর সময় অন্যরা যেন software-এ কাজ না করে। শেষ হলে database নিজে থেকে বর্তমান version-এর মতো update হয় (migrate)।</li>
                        <li><i class="fas fa-clipboard-check"></i> <strong>Check</strong> চাপলে backup টি আলাদা একটি অস্থায়ী database-এ restore করে প্রতিটি table-এর row মিলিয়ে দেখা হয় — আসল data-তে হাত পড়ে না। প্রতি রবিবার সর্বশেষ backup নিজে থেকে check হয়।</li>
                    </ol>
                </div>
            </div>
        </div>

        {{-- ---- dan pash ---- --}}
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="m-0"><i class="fas fa-database text-primary"></i> Back up now</h6></div>
                <form method="POST" action="{{ route('backups.store') }}" class="card-body" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').innerHTML = '<i class=\'fas fa-spinner fa-spin\'></i> Backing up...'; return true">
                    @csrf
                    <div class="form-group mb-2"><label class="mb-1"><strong>Note</strong> <span class="text-muted">(optional)</span></label>
                        <input type="text" name="note" maxlength="190" class="form-control" placeholder="যেমন: ঈদের দাম বদলের আগে"></div>
                    <button class="btn btn-primary btn-block"><i class="fas fa-database"></i> Back up now</button>
                    <div class="bk-hint mt-2">বড় database হলে এক-দুই মিনিট লাগতে পারে। পেজ বন্ধ করবেন না।</div>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0"><i class="far fa-calendar-check text-primary"></i> Automatic backups</h6></div>
                <form method="POST" action="{{ route('backups.schedule') }}" class="card-body">
                    @csrf
                    <div class="d-flex align-items-center mb-3">
                        <label class="switch mb-0 mr-2"><input type="checkbox" name="enabled" value="1" @checked($schedule->enabled)><span class="slider"></span></label>
                        <strong>Back up automatically</strong>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6"><label class="mb-1"><strong>How often</strong></label>
                            <select name="frequency" id="bk-frequency" class="form-control">
                                @foreach(\App\Models\BackupSchedule::FREQUENCIES as $k => $l)<option value="{{ $k }}" @selected($schedule->frequency === $k)>{{ $l }}</option>@endforeach
                            </select></div>
                        <div class="form-group col-6"><label class="mb-1"><strong>At</strong></label>
                            <input type="time" name="time" class="form-control" value="{{ $schedule->time }}" required></div>
                    </div>
                    <div class="form-group" id="bk-weekday" style="{{ $schedule->frequency === 'weekly' ? '' : 'display:none' }}"><label class="mb-1"><strong>On</strong></label>
                        <select name="weekday" class="form-control">
                            @foreach(\App\Models\BackupSchedule::WEEKDAYS as $i => $d)<option value="{{ $i }}" @selected($schedule->weekday === $i)>{{ $d }}</option>@endforeach
                        </select></div>
                    <div class="form-group mb-2"><label class="mb-1"><strong>Keep the last</strong></label>
                        <div class="input-group"><input type="number" name="keep" min="1" max="365" class="form-control" value="{{ $schedule->keep }}" required>
                            <div class="input-group-append"><span class="input-group-text">automatic backups</span></div></div></div>
                    <div class="bk-hint mb-3">এর চেয়ে পুরোনো automatic backup নিজে থেকে মুছে যায়। নিজে নেওয়া, আপলোড করা আর "Before restore" backup আপনি না মোছা পর্যন্ত থাকে।
                        এর জন্য scheduler চালু থাকতে হবে (<code>php artisan schedule:run</code> প্রতি মিনিটে)। সেই সময় কম্পিউটার বন্ধ থাকলে চালু হওয়ার পর backup হয়।</div>
                    <button class="btn btn-outline-primary btn-block">Save schedule</button>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0"><i class="fas fa-cloud-upload-alt text-primary"></i> Upload a backup</h6></div>
                <form method="POST" action="{{ route('backups.upload') }}" enctype="multipart/form-data" class="card-body"
                      onsubmit="this.querySelector('button').disabled = true; return true">
                    @csrf
                    <div class="form-group mb-2">
                        <input type="file" name="file" class="form-control" accept=".sql,.gz,.enc" required></div>
                    <div class="form-group mb-2"><input type="text" name="note" maxlength="190" class="form-control" placeholder="Note (optional)"></div>
                    <div class="bk-hint mb-3">এই পেজের <code>.sql.gz</code> backup অথবা MySQL / phpMyAdmin-এর <code>.sql</code> / <code>.sql.gz</code> export, সর্বোচ্চ {{ \App\Models\Backup::bytes($uploadLimit) }}।
                        প্রতিটি statement আগে যাচাই হয় — শুধু table-এর গঠন আর row নেওয়া হয়; অন্য কিছু (trigger, procedure, DROP DATABASE, UPDATE/DELETE ইত্যাদি) থাকলে পুরো file বাতিল।
                        আপলোড করলে কিছু restore হয় না — file টি তালিকায় যোগ হয়, সেখান থেকে restore করবেন।</div>
                    <button class="btn btn-outline-primary btn-block"><i class="fas fa-cloud-upload-alt"></i> Upload &amp; check</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    document.getElementById('bk-frequency').addEventListener('change', function () {
        document.getElementById('bk-weekday').style.display = this.value === 'weekly' ? '' : 'none';
    });
</script>
@endpush
