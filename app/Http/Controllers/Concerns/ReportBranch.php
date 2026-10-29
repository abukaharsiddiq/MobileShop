<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;
use Illuminate\Http\Request;

/**
 * Report er branch filter: onno branch er report na (403); shob branch er access na thakle branch na bachaile
 * nijer (mul) branch. Query te COALESCE(branch_id, default) - branch chara purono row default branch er.
 */
trait ReportBranch
{
    protected function reportBranch(Request $request): ?int
    {
        $user = $request->user();
        $request->validate(['branch_id' => 'nullable|integer|exists:branches,id']);
        $id = $request->integer('branch_id') ?: null;
        if ($id) {
            abort_unless($user->canAccessBranch($id), 403, 'You cannot see reports of this branch.');
            return $id;
        }

        return $user->canAccessAllBranches() ? null : (int) $user->branch_id;
    }

    // $column: branch_id er column (alias shoho), null branch = default branch
    protected function whereBranch($query, ?int $branchId, string $column)
    {
        return $query->when($branchId, fn ($q) => $q->whereRaw("COALESCE({$column}, ?) = ?", [(int) Branch::where('is_default', 1)->value('id'), $branchId]));
    }
}
