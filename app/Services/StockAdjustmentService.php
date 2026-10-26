<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MainStock;
use App\Models\PriceHistory;
use App\Models\ProductImei;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stock reconciliation:
 *  - IMEI registration: stock e thaka phone er asol IMEI register kora (stock quantity bodlay na)
 *  - Quantity correction: bhul/nosto/haralo/paoa stock thik kora (karon er dhoron shoho; device record na)
 *  - IMEI write-off: haralo/churi/nosto phone stock theke bad
 *  - Opening stock: shuru r stock (kena dam shoho, average cost e jog); IMEI wala hole protiti phone er IMEI
 *  - Branch transfer: ek branch theke arek branch (mot stock bodlay na)
 * Shob request age "pending"; approve korle transaction o lock er bhitore abar jachai kore apply hoy.
 * Ekoi form token (request_token) duibar ashle ekta request; ekoi adjustment er stock line duibar na (key).
 */
class StockAdjustmentService
{
    public function __construct(
        private ImeiService $imeiService,
        private StockService $stockService,
    ) {}

    public function request(array $data, User $user): StockAdjustment
    {
        return DB::transaction(function () use ($data, $user) {
            $token = trim((string) ($data['request_token'] ?? '')) ?: null;
            if ($token && ($existing = StockAdjustment::where('request_token', $token)->first())) {
                return $existing;
            }

            $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($data['main_stock_id']);
            $branch = Branch::where('status', 1)->findOrFail($data['branch_id']);
            $toBranchId = null;
            $unitCost = null;

            if ($data['type'] === StockAdjustment::TYPE_IMEI_REGISTRATION) {
                // protiti phone ekta line: "IMEI" ba dual-SIM "IMEI1/IMEI2"
                $imeis = ProductImei::parseList($data['imeis'] ?? '');
                $numbers = $this->validateRegistration($stock, $imeis, $data['verified_model_number'] ?? '');
                $this->imeiService->assertNotPendingRegistration($numbers);
                $quantity = count($imeis);
            } elseif ($data['type'] === StockAdjustment::TYPE_IMEI_WRITE_OFF) {
                // haralo/churi/nosto phone: IMEI 1 ba IMEI 2 diye; stock e IMEI 1 rakha hoy
                $devices = $this->writeOffDevices($stock, ProductImei::parseList($data['imeis'] ?? ''), false);
                $imeis = $devices->pluck('imei_no')->all();
                $quantity = -count($imeis);
            } elseif ($data['type'] === StockAdjustment::TYPE_OPENING_STOCK) {
                $imeis = ProductImei::parseList($data['imeis'] ?? '') ?: null;
                $quantity = (int) ($data['quantity'] ?? 0);
                $unitCost = round((float) ($data['unit_cost'] ?? -1), 2);
                $numbers = $this->validateOpening($stock, $quantity, $unitCost, $imeis ?? []);
                $this->imeiService->assertNotPendingRegistration($numbers);
            } elseif ($data['type'] === StockAdjustment::TYPE_TRANSFER) {
                $toBranchId = (int) Branch::where('status', 1)->findOrFail($data['to_branch_id'] ?? 0)->id;
                [$quantity, $imeis] = $this->validateTransfer($stock, $branch->id, $toBranchId, (int) ($data['quantity'] ?? 0),
                    ProductImei::parseList($data['imeis'] ?? ''), false);
            } else {
                $imeis = null;
                $quantity = (int) ($data['quantity'] ?? 0);
                $this->validateCorrection($stock, $quantity, $data['reason_type'] ?? null);
            }

            $adjustment = StockAdjustment::create([
                'adjustment_no'         => $this->nextNumber(),
                'type'                  => $data['type'],
                'main_stock_id'         => $stock->id,
                'branch_id'             => $branch->id,
                'to_branch_id'          => $toBranchId,
                'quantity'              => $quantity,
                'unit_cost'             => $unitCost,
                'imeis'                 => $imeis,
                'verified_model_number' => $data['verified_model_number'] ?? null,
                'reason'                => $data['reason'],
                'reason_type'           => $data['reason_type'] ?? null,
                'request_token'         => $token,
                'stock_count_id'        => $data['stock_count_id'] ?? null,
                'status'                => StockAdjustment::STATUS_PENDING,
                'requested_by'          => $user->id,
            ]);

            AuditLogger::log('stock_adjustment.requested', $adjustment, null, $adjustment->only([
                'adjustment_no', 'type', 'main_stock_id', 'branch_id', 'to_branch_id', 'quantity', 'unit_cost', 'imeis',
                'verified_model_number', 'reason', 'reason_type',
            ]));

            return $adjustment;
        });
    }

    public function approve(StockAdjustment $adjustment, User $reviewer, ?string $note = null): StockAdjustment
    {
        $this->assertCanReview($adjustment, $reviewer);

        return DB::transaction(function () use ($adjustment, $reviewer, $note) {
            $adjustment = StockAdjustment::lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== StockAdjustment::STATUS_PENDING) {
                throw new \Exception("Adjustment {$adjustment->adjustment_no} is already {$adjustment->status}.");
            }

            $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($adjustment->main_stock_id);
            $stockBefore = $stock->quantity;
            $key = 'adjustment:' . $adjustment->id;

            if ($adjustment->type === StockAdjustment::TYPE_IMEI_REGISTRATION) {
                // pending thakar somoy obostha bodlate pare, tai lock er bhitore abar jachai
                $this->validateRegistration($stock, $adjustment->imeis, $adjustment->verified_model_number);

                foreach (ProductImei::parseDevices(implode(',', $adjustment->imeis)) as [$imei1, $imei2]) {
                    $this->imeiService->register($stock->product, $stock->id, $adjustment->branch_id, $imei1, $imei2,
                        ImeiService::REGISTRATION, $adjustment, $adjustment->reason);
                }
            } elseif ($adjustment->type === StockAdjustment::TYPE_IMEI_WRITE_OFF) {
                // pending er somoy bikri/ferot hoye gele approve hobe na
                $devices = $this->writeOffDevices($stock, $adjustment->imeis, true);
                foreach ($devices as $device) {
                    $this->imeiService->writeOff($device, $adjustment, $adjustment->reason);
                }
                $this->stockService->move($stock, -$devices->count(), $adjustment->movementType(), $adjustment, $adjustment->reason, $adjustment->branch_id, $key);
            } elseif ($adjustment->type === StockAdjustment::TYPE_OPENING_STOCK) {
                $this->applyOpening($adjustment, $stock, $key);
            } elseif ($adjustment->type === StockAdjustment::TYPE_TRANSFER) {
                [$quantity, $numbers] = $this->validateTransfer($stock, $adjustment->branch_id, $adjustment->to_branch_id,
                    (int) $adjustment->quantity, (array) $adjustment->imeis, true);
                foreach ($numbers ? $this->transferDevices($stock, $adjustment->branch_id, $numbers, true) : [] as $device) {
                    $this->imeiService->transfer($device, $adjustment->to_branch_id, $adjustment, $adjustment->reason);
                }
                // IMEI wala hole phone e hishab (purono ledger e branch na thakte pare), na hole branch er ledger theke
                $this->stockService->transfer($stock, $quantity, $adjustment->branch_id, $adjustment->to_branch_id, $adjustment,
                    $adjustment->reason, $key, !$numbers);
            } else {
                $this->validateCorrection($stock, $adjustment->quantity, $adjustment->reason_type);
                $this->stockService->move($stock, $adjustment->quantity, $adjustment->movementType(), $adjustment, $adjustment->reason, $adjustment->branch_id, $key);
            }

            $adjustment->update([
                'status'       => StockAdjustment::STATUS_APPROVED,
                'stock_before' => $stockBefore,
                'stock_after'  => $stock->fresh()->quantity,
                'reviewed_by'  => $reviewer->id,
                'reviewed_at'  => now(),
                'review_note'  => $note,
            ]);

            AuditLogger::log('stock_adjustment.approved', $adjustment,
                ['status' => StockAdjustment::STATUS_PENDING, 'stock' => $stockBefore],
                ['status' => StockAdjustment::STATUS_APPROVED, 'stock' => $adjustment->stock_after, 'review_note' => $note]);

            return $adjustment;
        });
    }

    public function reject(StockAdjustment $adjustment, User $reviewer, string $note): StockAdjustment
    {
        $this->assertCanReview($adjustment, $reviewer);

        return DB::transaction(function () use ($adjustment, $reviewer, $note) {
            $adjustment = StockAdjustment::lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== StockAdjustment::STATUS_PENDING) {
                throw new \Exception("Adjustment {$adjustment->adjustment_no} is already {$adjustment->status}.");
            }

            $adjustment->update([
                'status'      => StockAdjustment::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            AuditLogger::log('stock_adjustment.rejected', $adjustment,
                ['status' => StockAdjustment::STATUS_PENDING],
                ['status' => StockAdjustment::STATUS_REJECTED, 'review_note' => $note]);

            return $adjustment;
        });
    }

    /**
     * Stock e IMEI chara koyta unit ache (registration er jonno faka jayga).
     */
    public static function unregisteredCount(MainStock $stock): int
    {
        return max(0, $stock->quantity - self::activeImeiCount($stock));
    }

    public static function activeImeiCount(MainStock $stock): int
    {
        // dokhole thaka shob (available, reserved, repair, damaged) - stock quantity te ei gulo gona
        return ProductImei::where('main_stock_id', $stock->id)->owned()->count();
    }

    // fere: shob IMEI number (IMEI 1 + IMEI 2)
    private function validateRegistration(MainStock $stock, array $imeis, ?string $verifiedModel): array
    {
        $product = $stock->product;
        $label = $stock->variantLabel();

        if (!$product->allowsImei()) {
            throw new \Exception("{$label} is an accessory and cannot have IMEI.");
        }

        // jei phone hate ache tar model number product er sathe milte hobe
        $expected = trim($product->model_number ?: $product->product_name);
        if (strcasecmp(trim((string) $verifiedModel), $expected) !== 0) {
            throw new \Exception("Model verification failed: the model number on the device must match \"{$expected}\".");
        }

        if (count($imeis) == 0) {
            throw new \Exception('Enter at least one IMEI.');
        }

        // dual-SIM model e IMEI 1/IMEI 2; shob number 15 digit + Luhn
        $numbers = $this->imeiService->assertNewDevices($product, ProductImei::parseDevices(implode(',', $imeis)), $label);

        $unregistered = self::unregisteredCount($stock);
        if (count($imeis) > $unregistered) {
            throw new \Exception("{$label} has only {$unregistered} unit(s) without IMEI, but " . count($imeis) . ' IMEI given.');
        }

        $this->imeiService->assertNotActive($numbers);
        $this->imeiService->assertNeverLeftStock($numbers);

        return $numbers;
    }

    /**
     * Opening stock: qty > 0, kena dam >= 0; IMEI lage emon product e protiti phone er IMEI (qty = sonkhya),
     * je IMEI kokhono dokan theke ber hoy ni ar ekhon stock e nei. Fere: IMEI number gulo.
     */
    private function validateOpening(MainStock $stock, int $quantity, float $unitCost, array $imeis): array
    {
        $label = $stock->variantLabel();
        if ($quantity < 1) {
            throw new \Exception("{$label}: opening quantity must be at least 1.");
        }
        if ($unitCost < 0) {
            throw new \Exception("{$label}: enter the unit cost of the opening stock.");
        }

        $devices = ProductImei::parseDevices(implode(',', $imeis));
        $this->imeiService->assertProductRules($stock->product, $quantity, $devices, $label);
        $numbers = $this->imeiService->assertNewDevices($stock->product, $devices, $label);
        $this->imeiService->assertNotActive($numbers);
        $this->imeiService->assertNeverLeftStock($numbers);

        return $numbers;
    }

    private function applyOpening(StockAdjustment $adjustment, MainStock $stock, string $key): void
    {
        $this->validateOpening($stock, (int) $adjustment->quantity, (float) $adjustment->unit_cost, (array) $adjustment->imeis);

        // weighted average (ager stock er dam nosto na kore)
        $qty = (int) $adjustment->quantity;
        $oldAvg = (float) $stock->average_purchase_price;
        $current = (int) $stock->quantity;
        $stock->average_purchase_price = round(($current * $oldAvg + $qty * (float) $adjustment->unit_cost) / ($current + $qty), 2);
        PriceHistory::record($stock->product, $stock, PriceHistory::AVERAGE_COST, $oldAvg, $stock->average_purchase_price, 'opening_stock', $adjustment);

        $this->stockService->move($stock, $qty, StockService::OPENING, $adjustment, $adjustment->reason, $adjustment->branch_id, $key);

        foreach (ProductImei::parseDevices(implode(',', (array) $adjustment->imeis)) as [$imei1, $imei2]) {
            $this->imeiService->register($stock->product, $stock->id, $adjustment->branch_id, $imei1, $imei2,
                ImeiService::REGISTRATION, $adjustment, 'Opening stock: ' . $adjustment->reason);
        }
    }

    /**
     * Transfer: IMEI wala variant hole phone gulo (source branch e, available/damaged), qty = sonkhya;
     * na hole qty, source branch er ledger e jotota ache tar beshi na. Fere [qty, IMEI 1 number].
     */
    private function validateTransfer(MainStock $stock, int $fromBranchId, ?int $toBranchId, int $quantity, array $numbers, bool $lock): array
    {
        $label = $stock->variantLabel();
        if (!$toBranchId || $toBranchId === $fromBranchId) {
            throw new \Exception('Choose a different branch to transfer to.');
        }

        $usesImei = $stock->product->requiresImei() || self::activeImeiCount($stock) > 0;
        if ($usesImei) {
            $devices = $this->transferDevices($stock, $fromBranchId, $numbers, $lock);
            return [$devices->count(), $devices->pluck('imei_no')->all()];
        }

        if ($numbers) {
            throw new \Exception("{$label} has no IMEI; enter a quantity instead.");
        }
        if ($quantity < 1) {
            throw new \Exception("{$label}: transfer quantity must be at least 1.");
        }
        $available = StockService::branchBalance($stock->id, $fromBranchId);
        if ($quantity > $available) {
            throw new \Exception("{$label}: only {$available} in the source branch, cannot transfer {$quantity}.");
        }

        return [$quantity, null];
    }

    private function transferDevices(MainStock $stock, int $fromBranchId, array $numbers, bool $lock)
    {
        $numbers = array_values(array_unique(array_filter($numbers)));
        if (!$numbers) {
            throw new \Exception("{$stock->variantLabel()}: enter the IMEI of each phone to transfer.");
        }

        $devices = ProductImei::where('main_stock_id', $stock->id)
            ->where(fn ($q) => $q->whereIn('imei_no', $numbers)->orWhereIn('imei_no_2', $numbers))
            ->whereIn('status', [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_DAMAGED])
            ->where(fn ($q) => $q->where('branch_id', $fromBranchId)->orWhereNull('branch_id'))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();

        $found = $devices->flatMap(fn ($d) => [$d->imei_no, $d->imei_no_2])->filter()->all();
        $missing = array_diff($numbers, $found);
        if ($missing) {
            throw new \Exception("{$stock->variantLabel()}: these IMEI are not available in the source branch: " . implode(', ', $missing));
        }

        return $devices->unique('id')->values();
    }

    /**
     * Write off er phone: ei variant er, dokhole (available/repair/damaged; reserved hole age unreserve).
     */
    private function writeOffDevices(MainStock $stock, array $numbers, bool $lock)
    {
        $numbers = array_values(array_unique(array_filter($numbers)));
        if (!$numbers) {
            throw new \Exception('Enter the IMEI of each phone to write off.');
        }

        $devices = ProductImei::where('main_stock_id', $stock->id)
            ->where(fn ($q) => $q->whereIn('imei_no', $numbers)->orWhereIn('imei_no_2', $numbers))
            ->whereIn('status', [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_IN_REPAIR, ProductImei::STATUS_DAMAGED])
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();

        $found = $devices->flatMap(fn ($d) => [$d->imei_no, $d->imei_no_2])->filter()->all();
        $missing = array_diff($numbers, $found);
        if ($missing) {
            throw new \Exception("{$stock->variantLabel()}: these IMEI are not in this variant's stock (or are reserved/sold): " . implode(', ', $missing));
        }

        return $devices->unique('id')->values();
    }

    private function validateCorrection(MainStock $stock, int $quantity, ?string $reasonType = null): void
    {
        $label = $stock->variantLabel();

        if ($quantity == 0) {
            throw new \Exception('Correction quantity cannot be zero.');
        }

        if ($reasonType && !isset(StockAdjustment::REASONS[$reasonType])) {
            throw new \Exception('Unknown reason type.');
        }
        // damaged/lost/stolen/expired mane stock kome; "found" mane bare
        if ($quantity > 0 && in_array($reasonType, ['damaged', 'lost', 'stolen', 'expired'], true)) {
            throw new \Exception("{$label}: damaged or lost stock must be a negative quantity.");
        }
        if ($quantity < 0 && $reasonType === 'found') {
            throw new \Exception("{$label}: found stock must be a positive quantity.");
        }

        if ($quantity > 0 && $stock->product->requiresImei()) {
            throw new \Exception("{$label} needs IMEI. Add phones through Purchase, or register IMEI for existing units.");
        }

        $newQty = $stock->quantity + $quantity;
        if ($newQty < 0) {
            throw new \Exception("{$label}: stock is {$stock->quantity}, cannot reduce by " . abs($quantity) . '.');
        }

        // registered device gulo correction diye muchha jabe na (segulo sale/return diye ber hoy)
        $active = self::activeImeiCount($stock);
        if ($newQty < $active) {
            throw new \Exception("{$label} has {$active} registered IMEI in stock; quantity cannot go below that. Use Sale or Purchase Return for registered devices.");
        }
    }

    private function assertCanReview(StockAdjustment $adjustment, User $reviewer): void
    {
        if (!$reviewer->can('approve stock adjustment')) {
            throw new \Exception('You are not allowed to approve stock adjustments.');
        }

        if (!$reviewer->canAccessBranch($adjustment->branch_id)) {
            throw new \Exception('You can only review stock corrections of your own branch.');
        }

        // nijer request nije approve shudhu admin korte pare (ek-maliker dokan); sob audit log e thake
        if ($adjustment->requested_by == $reviewer->id && $reviewer->role !== 'admin') {
            throw new \Exception('You cannot approve or reject your own adjustment request.');
        }
    }

    private function nextNumber(): string
    {
        $last = StockAdjustment::lockForUpdate()->latest('id')->value('id') ?? 0;

        return 'ADJ-' . str_pad($last + 1, 5, '0', STR_PAD_LEFT);
    }
}
