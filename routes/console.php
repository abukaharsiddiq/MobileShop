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
