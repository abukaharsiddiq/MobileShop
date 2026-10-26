<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Public registration bondho, tai notun install e prothom admin ei command diye banate hobe
Artisan::command('app:create-admin', function () {
    $data = [
        'name'     => $this->ask('Name'),
        'email'    => $this->ask('Email'),
        'phone'    => $this->ask('Phone'),
        'password' => $this->secret('Password (minimum 8 characters)'),
    ];

    $validator = Validator::make($data, [
        'name'     => 'required|string|max:255',
        'email'    => 'required|email|unique:users,email',
        'phone'    => 'required|unique:users,phone',
        'password' => 'required|min:8',
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }
        return 1;
    }

    $user = User::create([
        'name'     => $data['name'],
        'email'    => $data['email'],
        'phone'    => $data['phone'],
        'password' => Hash::make($data['password']),
        'role'     => 'admin',
        'status'   => 1,
    ]);

    Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole('admin');

    $this->info('Admin user created.');
})->purpose('Create an admin user');

// Read-only: stock ar IMEI er moddhe ki mil nei. Kono data bodlay na.
Artisan::command('inventory:audit', function (App\Services\InventoryAuditService $audit) {
    $problems = 0;

    foreach ($audit->run() as $issue) {
        $count = count($issue['rows']);
        $problems += $issue['severity'] === 'info' ? 0 : $count;
        $line = sprintf('[%s] %s: %d', strtoupper($issue['severity']), $issue['title'], $count);
        $count ? $this->warn($line) : $this->info($line);

        foreach ($issue['rows'] as $row) {
            $this->line('    - ' . $row);
        }
    }

    $this->newLine();
    $problems
        ? $this->warn("{$problems} record(s) need manual review. Fix them from Stock Reconciliation; nothing was changed.")
        : $this->info('No problems found.');
})->purpose('Report stock and IMEI records that need manual review (read-only)');

// Upgrade er por ekbar: bortoman stock er opening balance ar purono IMEI er itihash ledger e tola.
// Default dry-run; --apply dile tobei likhbe. Bar bar chalaleo duibar likhbe na.
Artisan::command('inventory:backfill-history {--apply : Write the records (otherwise dry-run)}', function () {
    $apply = $this->option('apply');

    $stocks = App\Models\MainStock::whereDoesntHave('movements')->get();
    $imeis = App\Models\ProductImei::whereNotExists(function ($q) {
        $q->select(Illuminate\Support\Facades\DB::raw(1))->from('imei_movements')->whereColumn('imei_movements.product_imei_id', 'product_imeis.id');
    })->get();

    $this->info(($apply ? 'Applying' : 'Dry-run') . ': ' . $stocks->count() . ' stock opening balance(s), ' . $imeis->count() . ' IMEI record(s) without history.');

    if (!$apply) {
        foreach ($stocks as $s) {
            $this->line("    stock #{$s->id}: opening {$s->quantity}");
        }
        $this->line('Run again with --apply to write. Take a database backup first.');
        return 0;
    }

    Illuminate\Support\Facades\DB::transaction(function () use ($stocks, $imeis) {
        foreach ($stocks as $s) {
            App\Models\StockMovement::create([
                'main_stock_id' => $s->id,
                'product_id'    => $s->product_id,
                'type'          => App\Services\StockService::OPENING,
                'quantity'      => $s->quantity,
                'balance_after' => $s->quantity,
                'note'          => 'Opening balance at upgrade (backfill)',
            ]);
        }

        foreach ($imeis as $i) {
            $base = [
                'imei_no' => $i->imei_no, 'product_imei_id' => $i->id, 'product_id' => $i->product_id,
                'main_stock_id' => $i->main_stock_id, 'branch_id' => $i->branch_id,
            ];
            App\Models\ImeiMovement::create($base + [
                'event' => $i->purchase_id ? App\Services\ImeiService::PURCHASE : App\Services\ImeiService::REGISTRATION,
                'status_after' => App\Models\ProductImei::STATUS_IN_STOCK,
                'reference_type' => $i->purchase_id ? 'Purchase' : null, 'reference_id' => $i->purchase_id,
                'note' => 'Backfilled from existing record', 'created_at' => $i->created_at,
            ]);
            if ($i->sale_id) {
                App\Models\ImeiMovement::create($base + [
                    'event' => App\Services\ImeiService::SALE, 'status_after' => App\Models\ProductImei::STATUS_SOLD,
                    'reference_type' => 'Sale', 'reference_id' => $i->sale_id,
                    'note' => 'Backfilled from existing record', 'created_at' => $i->updated_at,
                ]);
            } elseif ($i->status == App\Models\ProductImei::STATUS_RETURNED_TO_SUPPLIER) {
                App\Models\ImeiMovement::create($base + [
                    'event' => App\Services\ImeiService::PURCHASE_RETURN, 'status_after' => $i->status,
                    'note' => 'Backfilled from existing record', 'created_at' => $i->updated_at,
                ]);
            }
        }
    });

    $this->info('Done.');
})->purpose('Backfill opening stock balances and IMEI history once after upgrading (dry-run by default)');

// Customer/supplier ledger er running balance tarikh (date, id) onujayi abar hishab.
// Default dry-run (shudhu koto line bhul dekhay); --apply dile thik kore.
Artisan::command('ledger:recalculate {--apply : Fix the balances (otherwise dry-run)}', function (App\Services\LedgerService $ledger) {
    $apply = (bool) $this->option('apply');
    $total = 0;

    Illuminate\Support\Facades\DB::transaction(function () use ($ledger, $apply, &$total) {
        foreach (App\Models\Customer::orderBy('id')->get() as $customer) {
            $changed = $ledger->recalculateCustomer($customer->id, $apply);
            if ($changed) {
                $this->line("    customer #{$customer->id} {$customer->name}: {$changed} line(s), balance now " . number_format(App\Services\LedgerService::customerBalance($customer->id), 2));
            }
            $total += $changed;
        }

        foreach (App\Models\Supplier::orderBy('id')->get() as $supplier) {
            $changed = $ledger->recalculateSupplier($supplier->id, $apply);
            if ($changed) {
                $this->line("    supplier #{$supplier->id} {$supplier->name}: {$changed} line(s), balance now " . number_format(App\Services\LedgerService::supplierBalance($supplier->id), 2));
            }
            $total += $changed;
        }
    });

    $this->info(($apply ? 'Fixed' : 'Dry-run: would fix') . " {$total} ledger line(s)." . ($apply || !$total ? '' : ' Run again with --apply (take a backup first).'));
})->purpose('Recalculate customer and supplier ledger running balances by date (dry-run by default)');

// Security upgrade er por ekbar: notun role/permission toiri ar users.role onujayi Spatie role.
// Shudhu jog kore, kichu muchhe na. Default dry-run; --apply dile likhe.
// --keep-branch-access: branch e bandha purono user der 'access all branches' (ager moto shob branch). Shudhu upgrade er din.
Artisan::command('security:sync-roles {--apply : Write the changes (otherwise dry-run)} {--keep-branch-access : Give existing branch users access to all branches, as before the upgrade}', function (App\Services\RoleSyncService $sync) {
    $plan = $sync->plan((bool) $this->option('keep-branch-access'));

    $sections = [
        'roles'         => 'Roles to create',
        'permissions'   => 'Permissions to create',
        'user_roles'    => 'Users to get their role',
        'branch_access' => 'Users to keep all-branch access',
    ];
    foreach ($sections as $key => $title) {
        $this->line(sprintf('%s: %d', $title, count($plan[$key])));
        foreach ($plan[$key] as $row) {
            $this->line('    - ' . $row);
        }
    }

    if (App\Services\RoleSyncService::isEmpty($plan)) {
        $this->info('Nothing to change.');
        return 0;
    }

    if (!$this->option('apply')) {
        $this->warn('Dry-run: nothing was changed. Take a database backup, then run again with --apply.');
        return 0;
    }

    $sync->apply($plan);
    $this->info('Done. Nothing was removed.');
})->purpose('Create missing roles/permissions and give users their Spatie role (dry-run by default, adds only)');

// Product & variant upgrade er por: SKU nei emon purono variant e SKU (BRAND-MODEL-RAM-ROM-COLOR).
// Default dry-run; --apply dile likhe. Shudhu khali SKU bhore, kono SKU bodlay na.
Artisan::command('products:backfill-sku {--apply : Write the SKUs (otherwise dry-run)}', function (App\Services\VariantService $variants) {
    $stocks = App\Models\MainStock::with('product.brand')->whereNull('sku')->orderBy('id')->get();
    $this->info(($this->option('apply') ? 'Applying' : 'Dry-run') . ': ' . $stocks->count() . ' variant(s) without SKU.');

    $apply = (bool) $this->option('apply');
    Illuminate\Support\Facades\DB::transaction(function () use ($stocks, $variants, $apply) {
        foreach ($stocks as $stock) {
            $sku = $variants->generateSku($stock->product, $stock->ram_id, $stock->rom_id, $stock->color_id, $stock->id);
            $this->line("    variant #{$stock->id} {$stock->variantLabel()}: {$sku}");
            if ($apply) {
                // shudhu SKU column (quantity/dam chhoy na)
                App\Models\MainStock::whereKey($stock->id)->update(['sku' => $sku]);
            }
        }
    });

    if (!$apply && $stocks->isNotEmpty()) {
        $this->warn('Nothing was changed. Take a database backup, then run again with --apply.');
    }
})->purpose('Fill missing variant SKUs (dry-run by default; never changes an existing SKU)');

// Supplier ledger ki purchase/return/payment er sathe mele? Takar gormil shudhu dekhay (bodlay na).
// --apply dile shudhu hishab theke ber hoy emon column thik kore: running balance ar invoice er paid/return/due.
Artisan::command('suppliers:reconcile {--apply : Fix running balances and invoice paid/due (otherwise dry-run)} {--supplier= : Only this supplier id}', function (App\Services\SupplierReconciliationService $reconcile) {
    $apply = (bool) $this->option('apply');
    $suppliers = App\Models\Supplier::orderBy('id')
        ->when($this->option('supplier'), fn ($q, $id) => $q->whereKey($id))
        ->get();
    $issues = 0;
    $fixable = 0;

    Illuminate\Support\Facades\DB::transaction(function () use ($suppliers, $reconcile, $apply, &$issues, &$fixable) {
        foreach ($suppliers as $supplier) {
            $result = $reconcile->check($supplier);
            if (!$result['issues'] && !$result['running'] && !$result['invoices']) {
                continue;
            }

            $this->line("supplier #{$supplier->id} {$supplier->name}:");
            foreach ($result['issues'] as $issue) {
                $this->warn('    ' . $issue);
            }
            if ($result['running'] || $result['invoices']) {
                $this->line("    {$result['running']} running balance(s), {$result['invoices']} invoice paid/due " . ($apply ? 'fixed' : 'to fix'));
                if ($apply) {
                    $reconcile->apply($supplier);
                }
            }
            $issues += count($result['issues']);
            $fixable += $result['running'] + $result['invoices'];
        }
    });

    $this->info(($apply ? 'Fixed' : 'Dry-run: would fix') . " {$fixable} calculated value(s). {$issues} money mismatch(es) need manual review.");
    if (!$apply && $fixable) {
        $this->warn('Take a database backup, then run again with --apply.');
    }

    return $issues ? 1 : 0;
})->purpose('Check supplier ledgers against purchases, returns and payments (dry-run by default; never changes amounts)');

// Negative stock (shudhu IMEI chara product): config('inventory.allow_negative_stock') er sathe database er
// CHECK constraint ta o bondho korte hoy. disable abar constraint dey - kono stock negative thakle na.
// Default dry-run; --apply dile kore. Backup niye chalan.
Artisan::command('inventory:negative-stock {mode : enable or disable} {--apply : Change the database (otherwise dry-run)}', function () {
    $mode = $this->argument('mode');
    if (!in_array($mode, ['enable', 'disable'], true)) {
        $this->error('Mode must be "enable" or "disable".');
        return 1;
    }

    $active = App\Services\StockService::negativeConstraintActive(true);
    $negative = App\Models\MainStock::where('quantity', '<', 0)->count();
    $this->line('Database check constraint: ' . ($active ? 'ON (negative stock blocked)' : 'OFF') . '; config allow_negative_stock: '
        . (config('inventory.allow_negative_stock') ? 'true' : 'false') . "; variants below zero: {$negative}");

    if (($mode === 'enable') !== $active) {
        $this->info('Nothing to change.');
        return 0;
    }
    if ($mode === 'disable' && $negative) {
        $this->error("{$negative} variant(s) are below zero. Fix them (purchase or adjustment) before blocking negative stock again.");
        return 1;
    }
    if (!$this->option('apply')) {
        $this->warn('Dry-run: nothing was changed. Take a database backup, then run again with --apply.');
        return 0;
    }

    $name = App\Services\StockService::NEGATIVE_CONSTRAINT;
    Illuminate\Support\Facades\DB::statement($mode === 'enable'
        ? "ALTER TABLE main_stocks DROP CONSTRAINT {$name}"
        : "ALTER TABLE main_stocks ADD CONSTRAINT {$name} CHECK (quantity >= 0)");
    App\Services\AuditLogger::log('inventory.negative_stock_' . $mode . 'd');

    $this->info($mode === 'enable'
        ? 'Negative stock is now allowed by the database for items without IMEI (when ALLOW_NEGATIVE_STOCK=true).'
        : 'Negative stock is blocked by the database again.');
})->purpose('Allow or block negative stock for non-IMEI items at database level (dry-run by default)');

// Ekoi phone number (alada format e: 01711-..., +880...) er ekadhik customer - shudhu dekhay, merge kore na.
Artisan::command('customers:duplicates', function () {
    $customers = App\Models\Customer::orderBy('id')->get(['id', 'name', 'phone', 'alt_phone', 'phone_normalized', 'alt_phone_normalized']);
    $byNumber = [];
    foreach ($customers as $c) {
        foreach (array_unique(array_filter([$c->phone_normalized, $c->alt_phone_normalized])) as $number) {
            $byNumber[$number][$c->id] = $c;
        }
    }
    $groups = array_filter($byNumber, fn ($list) => count($list) > 1);

    foreach ($groups as $number => $list) {
        $this->warn("{$number}:");
        foreach ($list as $c) {
            $this->line("    #{$c->id} {$c->name} ({$c->phone}" . ($c->alt_phone ? ", {$c->alt_phone}" : '') . ')');
        }
    }
    $this->info(count($groups) . ' phone number(s) shared by more than one customer. Nothing was changed; review and merge them by hand.');

    return $groups ? 1 : 0;
})->purpose('List customers that share a phone number in any format (read-only)');

// Customer ledger ki sale record er sathe mele? (opening + invoice due = ledger balance). --apply shudhu running balance thik kore.
Artisan::command('customers:reconcile {--apply : Fix running balances (otherwise dry-run)} {--customer= : Only this customer id}', function (App\Services\CustomerAccountService $accounts, App\Services\LedgerService $ledger) {
    $apply = (bool) $this->option('apply');
    $issues = 0;
    $fixable = 0;

    $customers = App\Models\Customer::orderBy('id')->when($this->option('customer'), fn ($q, $id) => $q->whereKey($id))->get();
    Illuminate\Support\Facades\DB::transaction(function () use ($customers, $accounts, $ledger, $apply, &$issues, &$fixable) {
        foreach ($customers as $customer) {
            $result = $accounts->check($customer);
            if (!$result['issues'] && !$result['running']) {
                continue;
            }
            $this->line("customer #{$customer->id} {$customer->name}:");
            foreach ($result['issues'] as $issue) {
                $this->warn('    ' . $issue);
            }
            if ($result['running']) {
                $this->line("    {$result['running']} running balance(s) " . ($apply ? 'fixed' : 'to fix'));
                if ($apply) {
                    $ledger->recalculateCustomer($customer->id);
                }
            }
            $issues += count($result['issues']);
            $fixable += $result['running'];
        }
    });

    $this->info(($apply ? 'Fixed' : 'Dry-run: would fix') . " {$fixable} running balance(s). {$issues} mismatch(es) need manual review.");

    return $issues ? 1 : 0;
})->purpose('Check customer ledgers against sales (dry-run by default; never changes amounts)');

// Config (INSTALLMENT_LATE_FEE_*) onujayi late fee. Default dry-run; --apply dile bosay. Ekta kisti te ekbar-i.
Artisan::command('installments:apply-late-fees {--apply : Add the fees (otherwise dry-run)}', function (App\Services\InstallmentService $installments) {
    $rule = config('installments.late_fee');
    if ($rule['type'] === 'none') {
        $this->info('Late fee is off (INSTALLMENT_LATE_FEE_TYPE=none). Nothing to do.');
        return 0;
    }

    if (!$this->option('apply')) {
        $total = 0;
        foreach ($installments->lateFeeCandidates() as $i) {
            $fee = App\Services\InstallmentService::lateFeeFor($i);
            $total += $fee;
            $this->line("    {$i->sale->invoice_no} #{$i->installment_no} due {$i->due_date}: fee " . number_format($fee, 2));
        }
        $this->warn('Dry-run: would add ' . number_format($total, 2) . '. Run again with --apply.');
        return 0;
    }

    $applied = Illuminate\Support\Facades\DB::transaction(fn () => $installments->assessLateFees());
    $this->info(count($applied) . ' late fee(s) added, total ' . number_format(array_sum(array_column($applied, 'fee')), 2) . '.');
})->purpose('Add configured late fees to overdue installments (dry-run by default)');

// Read-only: kistir schedule, payment bhag ar invoice due ki mele
Artisan::command('installments:verify {--sale= : Only this sale id}', function (App\Services\InstallmentService $installments) {
    $issues = 0;
    App\Models\Sale::whereHas('installments')->when($this->option('sale'), fn ($q, $id) => $q->whereKey($id))
        ->orderBy('id')->each(function ($sale) use ($installments, &$issues) {
            foreach ($installments->verify($sale) as $issue) {
                $this->warn('    ' . $issue);
                $issues++;
            }
        });

    $issues ? $this->warn("{$issues} mismatch(es) need manual review. Nothing was changed.") : $this->info('All installment schedules match their payments.');

    return $issues ? 1 : 0;
})->purpose('Check installment schedules against payments and invoice dues (read-only)');

// Read-only: journal balanced kina, customer/supplier ledger ar receivable/payable mile kina, kon document journal e nei.
Artisan::command('finance:audit', function (App\Services\FinanceAuditService $audit) {
    $problems = 0;

    foreach ($audit->run() as $issue) {
        $count = count($issue['rows']);
        $problems += $issue['severity'] === 'info' ? 0 : $count;
        $line = sprintf('[%s] %s: %d', strtoupper($issue['severity']), $issue['title'], $count);
        $count ? $this->warn($line) : $this->info($line);

        foreach ($issue['rows'] as $row) {
            $this->line('    - ' . $row);
        }
    }

    $this->newLine();
    $problems ? $this->warn("{$problems} item(s) need review; nothing was changed.") : $this->info('Books are consistent.');

    return $problems ? 1 : 0;
})->purpose('Check that the journal balances and matches customer/supplier ledgers (read-only)');

// Upgrade er por ekbar: purono sale/purchase/payment/return/expense journal e (nijer tarikhe). Default dry-run;
// --apply dile likhe. Bar bar chalaleo duibar post hoy na. Document ba ledger bodlay na.
Artisan::command('finance:backfill {--apply : Write the journal entries (otherwise dry-run)}', function (App\Services\FinanceBackfillService $backfill) {
    $apply = (bool) $this->option('apply');

    Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $count = $backfill->run();
    } catch (\Throwable $e) {
        Illuminate\Support\Facades\DB::rollBack();
        $this->error('Stopped, nothing was written: ' . $e->getMessage());
        return 1;
    }
    $apply ? Illuminate\Support\Facades\DB::commit() : Illuminate\Support\Facades\DB::rollBack();

    foreach ($count as $kind => $n) {
        $this->line(sprintf('    %-28s %d', $kind, $n));
    }
    $total = array_sum($count);
    $this->info(($apply ? 'Posted' : 'Dry-run: would post') . " {$total} journal entr" . ($total === 1 ? 'y' : 'ies') . '.');
    if (!$apply && $total) {
        $this->warn('Nothing was written. Take a database backup, then run again with --apply, then php artisan finance:audit.');
    }
})->purpose('Post existing sales, purchases, payments, returns and expenses to the journal once (dry-run by default)');
