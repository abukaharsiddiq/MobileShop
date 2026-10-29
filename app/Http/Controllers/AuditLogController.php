<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Audit log dekha (shudhu porar jonno - edit/delete er kono route nei). Filter, details (age/pore), ekta record er
 * puro itihash (audit + stock + IMEI + journal), CSV export (export o audit e), chain jachai.
 */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date', 'user_id' => 'nullable|integer', 'record_id' => 'nullable|integer']);

        return view('back-end.audit.index', [
            'name'    => 'Audit Log',
            'title'   => 'Audit Log',
            'logs'    => $this->filtered($request)->with(['user:id,name', 'branch:id,name'])->orderByDesc('id')->paginate(50)->withQueryString(),
            'users'   => User::orderBy('name')->get(['id', 'name']),
            'areas'   => AuditLog::query()->selectRaw("DISTINCT SUBSTRING_INDEX(action, '.', 1) AS area")->orderBy('area')->pluck('area'),
            'types'   => AuditLog::query()->whereNotNull('auditable_type')->distinct()->pluck('auditable_type')->map(fn ($t) => class_basename($t))->unique()->sort()->values(),
            'chain'   => \App\Models\SystemHeartbeat::meta(AuditChain::HEARTBEAT),
        ]);
    }

    public function show($id)
    {
        $log = AuditLog::with(['user:id,name,email', 'branch:id,name'])->findOrFail($id);

        return view('back-end.audit.show', ['name' => 'Audit Log', 'title' => 'Audit entry #' . $log->id, 'log' => $log]);
    }

    /**
     * Ekta record er itihash: audit log + (stock/IMEI/journal ja ache). $type = model er naam (Sale, Product ...).
     */
    public function history(string $type, int $id)
    {
        abort_unless(preg_match('/^[A-Z][A-Za-z]{1,60}$/', $type) && is_subclass_of("App\\Models\\{$type}", \Illuminate\Database\Eloquent\Model::class), 404);
        $class = "App\\Models\\{$type}";
        $record = $class::query()->withoutGlobalScopes()->find($id);

        $events = collect();
        AuditLog::with('user:id,name')->where('auditable_type', $class)->where('auditable_id', $id)->orderBy('id')->get()
            ->each(fn ($log) => $events->push(['at' => $log->created_at, 'kind' => 'audit', 'title' => $log->action, 'user' => $log->user->name ?? $log->user_name,
                'detail' => $log->changes(), 'link' => route('audit.show', $log->id)]));

        // stock ar IMEI er cholachol (ei record theke hoya, ba ei product/stock er)
        $stock = DB::table('stock_movements as m')->leftJoin('users as u', 'u.id', '=', 'm.user_id')->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('m.reference_type', $type)->where('m.reference_id', $id))
                ->when($type === 'Product', fn ($w) => $w->orWhere('m.product_id', $id))
                ->when($type === 'MainStock', fn ($w) => $w->orWhere('m.main_stock_id', $id)))
            ->orderBy('m.id')->limit(500)->get(['m.*', 'u.name as user_name', 'b.name as branch_name']);
        foreach ($stock as $m) {
            $events->push(['at' => \Illuminate\Support\Carbon::parse($m->created_at), 'kind' => 'stock', 'title' => "stock.{$m->type}", 'user' => $m->user_name,
                'detail' => ['quantity' => [null, $m->quantity], 'balance after' => [null, $m->balance_after], 'branch' => [null, $m->branch_name],
                    'reference' => [null, trim(($m->reference_type ?? '') . ' ' . ($m->reference_no ?? ''))]] + ($m->note ? ['note' => [null, $m->note]] : []),
                'link' => null]);
        }

        $imei = DB::table('imei_movements as m')->leftJoin('users as u', 'u.id', '=', 'm.user_id')->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('m.reference_type', $type)->where('m.reference_id', $id))
                ->when($type === 'ProductImei', fn ($w) => $w->orWhere('m.product_imei_id', $id))
                ->when($type === 'Product', fn ($w) => $w->orWhere('m.product_id', $id)))
            ->orderBy('m.id')->limit(500)->get(['m.*', 'u.name as user_name', 'b.name as branch_name']);
        foreach ($imei as $m) {
            $events->push(['at' => \Illuminate\Support\Carbon::parse($m->created_at), 'kind' => 'imei', 'title' => "imei.{$m->event}", 'user' => $m->user_name,
                'detail' => ['IMEI' => [null, $m->imei_no], 'branch' => [null, $m->branch_name], 'reference' => [null, trim(($m->reference_type ?? '') . ' ' . ($m->reference_no ?? ''))]]
                    + ($m->note ? ['note' => [null, $m->note]] : []),
                'link' => null]);
        }

        // hisab (journal)
        $journals = DB::table('journal_entries as j')->leftJoin('users as u', 'u.id', '=', 'j.created_by')
            ->where('j.source_type', $type)->where('j.source_id', $id)->orderBy('j.id')->get(['j.*', 'u.name as user_name']);
        foreach ($journals as $j) {
            $events->push(['at' => \Illuminate\Support\Carbon::parse($j->created_at), 'kind' => 'journal', 'title' => "journal.{$j->event}", 'user' => $j->user_name,
                'detail' => ['entry' => [null, $j->entry_no], 'amount' => [null, number_format((float) $j->amount, 2)], 'status' => [null, $j->status]]
                    + ($j->narration ? ['narration' => [null, $j->narration]] : []),
                'link' => null]);
        }

        return view('back-end.audit.history', [
            'name'   => 'Audit Log',
            'title'  => "History: {$type} #{$id}",
            'type'   => $type,
            'id'     => $id,
            'record' => $record,
            'label'  => $record ? AuditLogger::reference($record) : null,
            'events' => $events->sortBy(fn ($e) => $e['at']->getTimestamp())->values(),
        ]);
    }

    // CSV (Excel e khole); export nijeo audit e
    public function export(Request $request)
    {
        $query = $this->filtered($request)->with('user:id,name')->orderByDesc('id');
        AuditLogger::log('audit.exported', null, null, ['filters' => array_filter($request->only(['user_id', 'area', 'action', 'type', 'record_id', 'q', 'from_date', 'to_date']))]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Time', 'User', 'Branch ID', 'Action', 'Record', 'Record ID', 'Reference', 'Before', 'After', 'IP', 'URL']);
            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $log) {
                    fputcsv($out, [$log->id, $log->created_at, $log->user->name ?? $log->user_name, $log->branch_id, $log->action, $log->recordType(),
                        $log->auditable_id, $log->reference, json_encode($log->old_values, JSON_UNESCAPED_UNICODE), json_encode($log->new_values, JSON_UNESCAPED_UNICODE),
                        $log->ip_address, $log->url]);
                }
            });
            fclose($out);
        }, 'audit-log-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function verify(AuditChain $chain)
    {
        $result = $chain->verify();
        AuditLogger::log('audit.verified', null, null, ['ok' => $result['ok'], 'checked' => $result['checked']]);

        return back()->with($result['ok'] ? 'success' : 'error', $result['ok']
            ? "Audit log is intact ({$result['checked']} entries checked)."
            : 'Audit log was tampered with: ' . implode(' ', array_slice($result['problems'], 0, 5)));
    }

    private function filtered(Request $request)
    {
        $term = trim((string) $request->q);

        return AuditLog::query()
            ->when($request->user_id, fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->area, fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $a) . '.%'))
            ->when($request->action, fn ($q, $a) => $q->where('action', $a))
            ->when($request->type, fn ($q, $t) => $q->where('auditable_type', 'App\\Models\\' . $t))
            ->when($request->record_id, fn ($q, $r) => $q->where('auditable_id', $r))
            ->when($request->from_date, fn ($q, $d) => $q->where('created_at', '>=', $d . ' 00:00:00'))
            ->when($request->to_date, fn ($q, $d) => $q->where('created_at', '<=', $d . ' 23:59:59'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$term}%")->orWhere('ip_address', $term)->orWhere('user_name', 'like', "%{$term}%")));
    }
}
