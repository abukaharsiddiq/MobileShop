<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Services\AuditLogger;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Backup (shudhu "manage backups" - default e admin): ekhon backup (note shoho), nijer theke backup er niyom, upload
 * (jachai kore), jachai (alada database e test restore), download/restore (password abar), delete. Shob audit e.
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index()
    {
        return view('back-end.backups.index', [
            'name'        => 'Backups',
            'title'       => 'Database Backups',
            'backups'     => Backup::with('creator:id,name')->where('status', '!=', Backup::PRUNED)->orderByDesc('id')->paginate(25),
            'stats'       => $this->backups->stats(),
            'schedule'    => BackupSchedule::current(),
            'encrypted'   => (bool) config('backup.encryption_key'),
            'uploadLimit' => BackupService::uploadLimit(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(['note' => 'nullable|string|max:190']);
        try {
            $backup = $this->backups->run('manual', $request->user(), $request->note);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $backup->status === Backup::COMPLETED
            ? back()->with('success', "Backup তৈরি হয়েছে ({$backup->sizeLabel()}, {$backup->table_count}টি table)।")
            : back()->with('error', 'Backup ব্যর্থ হয়েছে: ' . $backup->error);
    }

    public function schedule(Request $request)
    {
        $data = $request->validate([
            'frequency' => ['required', Rule::in(array_keys(BackupSchedule::FREQUENCIES))],
            'time'      => ['required', 'date_format:H:i'],
            'weekday'   => ['nullable', 'integer', 'between:0,6'],
            'keep'      => ['required', 'integer', 'between:1,365'],
        ]);
        $schedule = BackupSchedule::first() ?? new BackupSchedule();
        $old = $schedule->exists ? $schedule->only(['enabled', 'frequency', 'time', 'weekday', 'keep']) : null;
        $schedule->fill($data + ['enabled' => $request->boolean('enabled'), 'weekday' => $data['weekday'] ?? 5, 'updated_by' => $request->user()->id])->save();
        AuditLogger::log('backup.schedule_updated', $schedule, $old, $schedule->only(['enabled', 'frequency', 'time', 'weekday', 'keep']));

        return back()->with('success', $schedule->enabled ? 'Automatic backup চালু: ' . $schedule->describe() . '।' : 'Automatic backup বন্ধ।');
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:' . (int) floor(BackupService::uploadLimit() / 1024)],
            'note' => 'nullable|string|max:190',
        ]);
        try {
            $backup = $this->backups->upload($request->file('file'), $request->user(), $request->note);
        } catch (\RuntimeException $e) {
            AuditLogger::log('backup.upload_refused', null, null, ['file' => mb_substr($request->file('file')->getClientOriginalName(), 0, 190), 'reason' => mb_substr($e->getMessage(), 0, 300)]);

            return back()->with('error', 'File নেওয়া হয়নি: ' . $e->getMessage());
        }

        return back()->with('success', "File যাচাই হয়ে তালিকায় যোগ হয়েছে ({$backup->table_count}টি table)। এখনো কিছু restore হয়নি।");
    }

    public function verify(Request $request, $id)
    {
        $result = $this->backups->verify(Backup::findOrFail($id), $request->user());

        return back()->with($result['status'] === 'failed' ? 'error' : 'success', ucfirst($result['status']) . ': ' . implode(' ', $result['notes']));
    }

    // password.confirm: shomprotik password dewa lagbe
    public function download($id)
    {
        $backup = Backup::findOrFail($id);
        abort_unless($backup->exists(), 404, 'The backup file is missing.');
        AuditLogger::log('backup.downloaded', $backup, null, ['filename' => $backup->filename, 'sha256' => $backup->sha256]);

        return response()->download($backup->path(), $backup->filename, ['Content-Type' => 'application/octet-stream']);
    }

    public function confirmRestore($id)
    {
        $backup = Backup::with('creator:id,name')->findOrFail($id);
        abort_unless($backup->exists(), 404, 'The backup file is missing.');

        return view('back-end.backups.restore', ['name' => 'Backups', 'title' => 'Restore backup', 'backup' => $backup, 'stats' => $this->backups->stats()]);
    }

    public function restore(Request $request, $id)
    {
        $backup = Backup::findOrFail($id);
        $request->validate(['confirm' => ['required', 'in:RESTORE']], ['confirm.in' => 'নিশ্চিত করতে বড় হাতের অক্ষরে RESTORE লিখুন।']);
        try {
            $result = $this->backups->restore($backup, $request->user());
        } catch (\RuntimeException $e) {
            return redirect()->route('backups.index')->with('error', $e->getMessage());
        }

        return redirect()->route('backups.index')->with('success', "Restore সম্পন্ন: {$result['tables']}টি table ফিরিয়ে আনা হয়েছে। "
            . "আগের অবস্থা \"Before restore\" backup #{$result['safety']->id}-এ রাখা আছে।");
    }

    public function destroy(Request $request, $id)
    {
        $backup = Backup::findOrFail($id);
        $this->backups->delete($backup, $request->user());

        return back()->with('success', 'Backup মুছে ফেলা হয়েছে।');
    }
}
