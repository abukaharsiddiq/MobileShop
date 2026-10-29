<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MainStock;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\RepairJob;
use App\Models\RepairJobEvent;
use App\Models\RepairJobPart;
use App\Models\RepairPayment;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repair & service center.
 *
 *  open (job card, advance) -> start (technician) <-> waiting parts -> complete (kaj, service charge)
 *    -> QC (pass: qc_passed, fail: abar in repair) -> deliver (invoice, baki taka, service warranty)
 *  delivery r age cancel: approved part stock e ferot (journal reverse), cancel charge (thakle) invoice, baki advance refund.
 *
 * - Part: technician chay (requested) -> 'approve repair' approve korle tobe stock kome (Dr Repair Parts Cost, Cr Inventory).
 * - Device: IMEI format; dokaner stock er phone na; ekoi IMEI er duita open job na; amader bikri hole kon customer er
 *   kache - onno customer er hole ownership proof lage.
 * - Taka: customer ledger + journal (receivable); advance agrim hisebe, invoice delivery te.
 * Shob public method nijer DB transaction e (job row lock).
 */
class RepairService
{
    const MAX_WARRANTY_DAYS = 365;

    public function __construct(
        private StockService $stock,
        private LedgerService $ledger,
        private FinancePostingService $finance,
    ) {}

    /**
     * $data: customer_id | new_customer_name + new_customer_phone, contact_phone?, alt_phone?, product_id?, device_model,
     *        device_color?, imei_no?, imei_no_2?, imei_missing_reason?, ownership_note?, complaint, physical_condition?,
     *        accessories[]?, estimated_cost?, estimated_delivery?, technician_id?, advance?, payment_method?,
     *        payment_reference?, account_id?, request_token?
     */
    public function open(array $data, User $user): RepairJob
    {
        $token = trim((string) ($data['request_token'] ?? '')) ?: null;
        if ($token && ($done = RepairJob::where('request_token', $token)->first())) {
            return $done;
        }

        return DB::transaction(function () use ($data, $user, $token) {
            $customer = $this->customer($data, $user);
            $product = !empty($data['product_id']) ? Product::findOrFail($data['product_id']) : null;
            if ($product && !$product->allowsImei()) {
                throw new \Exception("{$product->product_name} is not a phone model.");
            }
            $model = trim((string) ($data['device_model'] ?? '')) ?: trim(($product?->product_name ?? '') . ' ' . ($product?->model_number ?? ''));
            if ($model === '') {
                throw new \Exception('Enter the phone model.');
            }
            $complaint = trim((string) ($data['complaint'] ?? ''));
            if ($complaint === '') {
                throw new \Exception("Write the customer's complaint.");
            }

            $device = $this->verifyDevice($customer, (string) ($data['imei_no'] ?? ''), $data['imei_no_2'] ?? null,
                (string) ($data['imei_missing_reason'] ?? ''), (string) ($data['ownership_note'] ?? ''));

            $accessories = array_values(array_unique(array_filter((array) ($data['accessories'] ?? []))));
            if ($unknown = array_diff($accessories, array_keys(config('repair.accessories')))) {
                throw new \Exception('Unknown accessory: ' . implode(', ', $unknown));
            }
            $estimate = round((float) ($data['estimated_cost'] ?? 0), 2);
            if ($estimate < 0) {
                throw new \Exception('Estimated cost cannot be negative.');
            }
            $deliveryDate = ($data['estimated_delivery'] ?? null) ? Carbon::parse($data['estimated_delivery'])->toDateString() : null;
            if ($deliveryDate && $deliveryDate < now()->toDateString()) {
                throw new \Exception('Estimated delivery date cannot be in the past.');
            }
            $technician = !empty($data['technician_id']) ? $this->technician((int) $data['technician_id']) : null;

            try {
                $job = RepairJob::create($device + [
                    'job_no'             => 'TMP-' . Str::random(20),
                    'branch_id'          => $user->branch_id ?? \App\Models\Branch::currentId(),
                    'customer_id'        => $customer->id,
                    'contact_phone'      => trim((string) ($data['contact_phone'] ?? '')) ?: $customer->phone,
                    'alt_phone'          => trim((string) ($data['alt_phone'] ?? '')) ?: $customer->alt_phone,
                    'product_id'         => $product?->id,
                    'device_model'       => $model,
                    'device_color'       => trim((string) ($data['device_color'] ?? '')) ?: null,
                    'complaint'          => $complaint,
                    'physical_condition' => trim((string) ($data['physical_condition'] ?? '')) ?: null,
                    'accessories'        => $accessories,
                    'estimated_cost'     => $estimate,
                    'estimated_delivery' => $deliveryDate,
                    'technician_id'      => $technician?->id,
                    'status'             => RepairJob::RECEIVED,
                    'request_token'      => $token,
                    'received_by'        => $user->id,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'repair_jobs_open_imei_unique')) {
                    throw new \Exception("IMEI {$device['imei_no']} already has an open repair job.");
                }
                throw $e;
            }
            $job->update(['job_no' => 'RJ-' . str_pad((string) $job->id, 6, '0', STR_PAD_LEFT)]);

            $this->event($job, RepairJob::RECEIVED, $user, $complaint . ($job->under_warranty ? " (repair warranty of {$job->previousJob->job_no})" : ''));
            if ($technician) {
                $this->event($job, 'assigned', $user, $technician->name);
            }

            $advance = round((float) ($data['advance'] ?? 0), 2);
            if ($advance < 0) {
                throw new \Exception('Advance cannot be negative.');
            }
            if ($advance > 0) {
                $this->pay($job, $user, RepairPayment::ADVANCE, $advance, (string) ($data['payment_method'] ?? 'Cash'),
                    $data['payment_reference'] ?? null, ($data['account_id'] ?? null) ? (int) $data['account_id'] : null);
            }
            AuditLogger::log('repair.opened', $job, null, $job->only(['job_no', 'customer_id', 'device_model', 'imei_no', 'ownership', 'estimated_cost']));
            $this->notifyCustomer($job, 'repair_received');

            return $job->refresh();
        });
    }

    /**
     * IMEI jachai ar device kar: [imei_no, imei_no_2, imei_missing_reason, ownership, ownership_note, product_imei_id, sale_id,
     * previous_job_id, under_warranty]. Form (save er age) o eta dake.
     */
    public function verifyDevice(Customer $customer, string $imei1, ?string $imei2, string $missingReason = '', string $ownershipNote = ''): array
    {
        $imei1 = trim($imei1);
        $imei2 = trim((string) $imei2) ?: null;
        $note = trim($ownershipNote) ?: null;
        $result = ['imei_no' => null, 'imei_no_2' => null, 'imei_missing_reason' => null, 'ownership' => 'declared', 'ownership_note' => $note,
            'product_imei_id' => null, 'sale_id' => null, 'previous_job_id' => null, 'under_warranty' => false];

        if ($imei1 === '') {
            if ($imei2 || trim($missingReason) === '') {
                throw new \Exception('Enter IMEI 1, or write why it cannot be read (e.g. dead board).');
            }
            return ['imei_missing_reason' => trim($missingReason)] + $result;
        }

        $numbers = array_values(array_filter([$imei1, $imei2]));
        if ($invalid = array_filter($numbers, fn ($n) => !ProductImei::isValidFormat($n))) {
            throw new \Exception('Invalid IMEI (must be 15 digits with a valid check digit): ' . implode(', ', $invalid));
        }
        if ($imei1 === $imei2) {
            throw new \Exception('IMEI 1 and IMEI 2 cannot be the same.');
        }
        $match = fn ($q) => $q->whereIn('imei_no', $numbers)->orWhereIn('imei_no_2', $numbers);

        // dokaner dokhole thaka phone customer er hote pare na
        if ($stock = ProductImei::owned()->where($match)->first()) {
            throw new \Exception("IMEI {$stock->numbersLabel()} is in shop stock ({$stock->statusText()}), not a customer's phone.");
        }
        if ($open = RepairJob::open()->where($match)->first()) {
            throw new \Exception("IMEI {$imei1} already has an open repair job ({$open->job_no}).");
        }

        // amader bikri kora phone: kon customer er kache
        $sold = ProductImei::with('sale.customer')->where('status', ProductImei::STATUS_SOLD)->where($match)->latest('id')->first();
        if ($sold) {
            $known = array_filter([$sold->imei_no, $sold->imei_no_2]);
            if (!in_array($imei1, $known, true) || ($imei2 && !in_array($imei2, $known, true))) {
                throw new \Exception("IMEI numbers do not match our record of this phone ({$sold->numbersLabel()}).");
            }
            $result['product_imei_id'] = $sold->id;
            $result['sale_id'] = $sold->sale_id;
            [$imei1, $imei2] = [$sold->imei_no, $sold->imei_no_2];

            if ((int) $sold->sale?->customer_id === (int) $customer->id) {
                $result['ownership'] = 'shop_sale';
            } else {
                if (!$note) {
                    throw new \Exception("This phone was sold by the shop to another customer (" . ($sold->sale?->customer?->name ?? 'unknown')
                        . ", {$sold->sale?->invoice_no}). Check the ownership proof and write it in the ownership note.");
                }
                $result['ownership'] = 'proof';
            }
        }

        // ager repair er service warranty
        $previous = RepairJob::where('status', RepairJob::DELIVERED)->where($match)
            ->whereDate('warranty_until', '>=', now()->toDateString())->latest('id')->first();

        return ['imei_no' => $imei1, 'imei_no_2' => $imei2, 'previous_job_id' => $previous?->id, 'under_warranty' => (bool) $previous] + $result;
    }

    // ---- technician / status ---------------------------------------------------------------------

    public function assign(RepairJob $job, User $user, int $technicianId): RepairJob
    {
        return DB::transaction(function () use ($job, $user, $technicianId) {
            $job = $this->lock($job, [RepairJob::RECEIVED, RepairJob::IN_REPAIR, RepairJob::WAITING_PARTS]);
            $technician = $this->technician($technicianId);

            $job->update(['technician_id' => $technician->id]);
            $this->event($job, 'assigned', $user, $technician->name);

            return $job;
        });
    }

    // Kaj shuru: technician na thakle je shuru korlo se
    public function start(RepairJob $job, User $user, ?string $diagnosis = null, ?float $estimate = null): RepairJob
    {
        return DB::transaction(function () use ($job, $user, $diagnosis, $estimate) {
            $job = $this->lock($job, [RepairJob::RECEIVED]);
            if (!$job->technician_id) {
                $job->technician_id = $this->technician($user->id)->id;
                $this->event($job, 'assigned', $user, $user->name);
            }
            $this->assertWorker($job, $user);

            $job->fill(['status' => RepairJob::IN_REPAIR, 'diagnosis' => trim((string) $diagnosis) ?: $job->diagnosis]);
            if ($estimate !== null) {
                $job->estimated_cost = $this->money($estimate, 'Estimated cost');
            }
            $job->save();
            $this->event($job, RepairJob::IN_REPAIR, $user, $diagnosis);

            return $job;
        });
    }

    public function waitForParts(RepairJob $job, User $user, bool $waiting, string $note): RepairJob
    {
        return DB::transaction(function () use ($job, $user, $waiting, $note) {
            $job = $this->lock($job, [$waiting ? RepairJob::IN_REPAIR : RepairJob::WAITING_PARTS]);
            $this->assertWorker($job, $user);

            $job->update(['status' => $waiting ? RepairJob::WAITING_PARTS : RepairJob::IN_REPAIR]);
            $this->event($job, $job->status, $user, $note);

            return $job;
        });
    }

    // Kaj shesh: technician shudhu ki kora holo likhe; service charge (labour) QC ba delivery te manager/counter dey.
    // Kono part approval baki thakle na.
    public function complete(RepairJob $job, User $user, string $workDone): RepairJob
    {
        return DB::transaction(function () use ($job, $user, $workDone) {
            $job = $this->lock($job, RepairJob::WORKING);
            $this->assertWorker($job, $user);
            if (trim($workDone) === '') {
                throw new \Exception('Write what was repaired.');
            }
            if ($job->parts()->where('status', RepairJobPart::REQUESTED)->exists()) {
                throw new \Exception('Some parts are still waiting for approval. Approve or reject them first.');
            }

            $job->update(['status' => RepairJob::COMPLETED, 'work_done' => trim($workDone), 'completed_at' => now()]);
            $this->event($job, RepairJob::COMPLETED, $user, $workDone);

            return $job;
        });
    }

    // Quality check: pass hole delivery r jonno (manager service charge o dite pare), fail hole abar kaj
    public function qualityCheck(RepairJob $job, User $user, bool $passed, string $note, ?float $serviceCharge = null): RepairJob
    {
        return DB::transaction(function () use ($job, $user, $passed, $note, $serviceCharge) {
            $job = $this->lock($job, [RepairJob::COMPLETED]);
            if (trim($note) === '') {
                throw new \Exception('Write the quality check result.');
            }
            if (config('repair.separate_qc') && (int) $job->technician_id === $user->id && !$user->isAdmin()) {
                throw new \Exception('The technician who repaired the phone cannot pass its quality check.');
            }

            if ($passed) {
                $job->update(['status' => RepairJob::QC_PASSED, 'qc_by' => $user->id, 'qc_at' => now(), 'qc_note' => $note]
                    + ($serviceCharge !== null ? ['service_charge' => $this->money($serviceCharge, 'Service charge')] : []));
                $this->event($job, RepairJob::QC_PASSED, $user, $note . ($serviceCharge !== null ? ' (service charge ' . number_format((float) $job->service_charge, 2) . ')' : ''));
                $this->notifyCustomer($job, 'repair_ready');
            } else {
                $job->update(['status' => RepairJob::IN_REPAIR, 'qc_note' => $note, 'completed_at' => null]);
                $this->event($job, 'qc_failed', $user, $note);
            }

            return $job;
        });
    }

    // ---- parts -----------------------------------------------------------------------------------

    public function requestPart(RepairJob $job, User $user, int $stockId, int $quantity, ?float $unitPrice = null, ?string $note = null): RepairJobPart
    {
        return DB::transaction(function () use ($job, $user, $stockId, $quantity, $unitPrice, $note) {
            $job = $this->lock($job, RepairJob::WORKING);
            $this->assertWorker($job, $user);

            $stock = MainStock::with('product')->findOrFail($stockId);
            if ($stock->product->allowsImei()) {
                throw new \Exception("{$stock->variantLabel()} is a phone, not a spare part.");
            }
            if (!$stock->isSellable()) {
                throw new \Exception("{$stock->variantLabel()} is inactive.");
            }
            if ($quantity < 1) {
                throw new \Exception('Part quantity must be at least 1.');
            }
            $price = $unitPrice === null ? (float) ($stock->sale_price ?? $stock->product->sale_price) : $unitPrice;

            $part = RepairJobPart::create([
                'repair_job_id' => $job->id,
                'product_id'    => $stock->product_id,
                'main_stock_id' => $stock->id,
                'quantity'      => $quantity,
                'unit_price'    => $this->money($price, 'Part price'),
                'status'        => RepairJobPart::REQUESTED,
                'note'          => trim((string) $note) ?: null,
                'requested_by'  => $user->id,
            ]);
            $this->event($job, 'part_requested', $user, "{$quantity} x {$stock->variantLabel()}");

            return $part;
        });
    }

    // Approve: tokhon-i stock kome (lock shoho, negative na) ar kena dam khoroch e
    public function approvePart(RepairJobPart $part, User $user): RepairJobPart
    {
        return DB::transaction(function () use ($part, $user) {
            $part = RepairJobPart::lockForUpdate()->findOrFail($part->id);
            $job = $this->lock($part->job, RepairJob::WORKING);
            if ($part->status !== RepairJobPart::REQUESTED) {
                throw new \Exception("This part is already {$part->statusLabel()}.");
            }

            $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($part->main_stock_id);
            $part->unit_cost = round((float) $stock->average_purchase_price, 2);
            $this->stock->move($stock, -$part->quantity, StockService::REPAIR_PART, $job, "Part for {$job->job_no}", $job->branch_id, 'repair_part:' . $part->id);

            $part->fill(['status' => RepairJobPart::APPROVED, 'approved_by' => $user->id, 'approved_at' => now()])->save();
            $job->update([
                'parts_total' => round((float) $job->parts_total + $part->lineTotal(), 2),
                'parts_cost'  => round((float) $job->parts_cost + $part->lineCost(), 2),
            ]);
            $this->finance->postRepairPart($part->setRelation('mainStock', $stock), $job);
            $this->event($job, 'part_approved', $user, "{$part->quantity} x {$stock->variantLabel()} @ " . number_format((float) $part->unit_price, 2));

            return $part;
        });
    }

    public function rejectPart(RepairJobPart $part, User $user, string $reason): RepairJobPart
    {
        return DB::transaction(function () use ($part, $user, $reason) {
            $part = RepairJobPart::lockForUpdate()->findOrFail($part->id);
            $job = $this->lock($part->job, RepairJob::WORKING);
            if ($part->status !== RepairJobPart::REQUESTED) {
                throw new \Exception("This part is already {$part->statusLabel()}.");
            }

            $part->update(['status' => RepairJobPart::REJECTED, 'approved_by' => $user->id, 'note' => trim(($part->note ?? '') . ' | Rejected: ' . $reason, ' |')]);
            $this->event($job, 'part_rejected', $user, $reason);

            return $part;
        });
    }

    // Lagano part khule stock e ferot (kaj cholakalin)
    public function returnPart(RepairJobPart $part, User $user, string $reason): RepairJobPart
    {
        return DB::transaction(function () use ($part, $user, $reason) {
            $part = RepairJobPart::lockForUpdate()->findOrFail($part->id);
            $job = $this->lock($part->job, RepairJob::WORKING);
            $this->restock($job, $part, $user, $reason);

            return $part;
        });
    }

    // ---- money / delivery / cancel ------------------------------------------------------------------

    /**
     * Advance (job open thakle) ba baki (delivery r por). Ekoi form (request_token) duibar ashle ekbar.
     */
    public function collect(RepairJob $job, User $user, float $amount, string $method, ?string $reference = null, ?int $accountId = null,
                            ?string $token = null): RepairPayment
    {
        if ($token && ($done = RepairPayment::where('request_token', $token)->first())) {
            return $done;
        }

        return DB::transaction(function () use ($job, $user, $amount, $method, $reference, $accountId, $token) {
            $job = RepairJob::lockForUpdate()->findOrFail($job->id);
            if ($job->status === RepairJob::CANCELLED) {
                throw new \Exception("Job {$job->job_no} is cancelled.");
            }
            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw new \Exception('Amount must be greater than zero.');
            }
            if ($job->status === RepairJob::DELIVERED && $amount > $job->due()) {
                throw new \Exception('Amount is more than the due (' . number_format(max(0, $job->due()), 2) . ').');
            }

            return $this->pay($job, $user, $job->status === RepairJob::DELIVERED ? RepairPayment::PAYMENT : RepairPayment::ADVANCE,
                $amount, $method, $reference, $accountId, $token);
        });
    }

    /**
     * $data: service_charge?, discount?, warranty_days?, amount?, payment_method?, payment_reference?, account_id?, collected_by?
     * Counter service charge (labour) churanto kore; invoice (service charge + parts - discount) post, baki taka,
     * beshi advance thakle ferot, service warranty shuru.
     */
    public function deliver(RepairJob $job, User $user, array $data): RepairJob
    {
        return DB::transaction(function () use ($job, $user, $data) {
            $job = $this->lock($job, [RepairJob::QC_PASSED]);
            if (($data['service_charge'] ?? null) !== null && $data['service_charge'] !== '') {
                $job->service_charge = $this->money((float) $data['service_charge'], 'Service charge');
            }

            $subtotal = round((float) $job->service_charge + (float) $job->parts_total, 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);
            if ($discount < 0 || $discount > $subtotal) {
                throw new \Exception('Discount must be between 0 and the bill (' . number_format($subtotal, 2) . ').');
            }
            $days = ($data['warranty_days'] ?? null) === null || $data['warranty_days'] === '' ? (int) config('repair.default_warranty_days') : (int) $data['warranty_days'];
            if ($days < 0 || $days > self::MAX_WARRANTY_DAYS) {
                throw new \Exception('Service warranty must be between 0 and ' . self::MAX_WARRANTY_DAYS . ' days.');
            }
            $total = round($subtotal - $discount, 2);
            $method = (string) ($data['payment_method'] ?? 'Cash');
            $amount = round((float) ($data['amount'] ?? 0), 2);
            $due = round($total - (float) $job->paid_amount, 2);
            if ($amount < 0 || $amount > max(0, $due)) {
                throw new \Exception('Payment must be between 0 and the due (' . number_format(max(0, $due), 2) . ').');
            }

            $job->update([
                'status'         => RepairJob::DELIVERED,
                'discount'       => $discount,
                'total'          => $total,
                'warranty_days'  => $days,
                'warranty_until' => $days > 0 ? now()->addDays($days)->toDateString() : null,
                'collected_by'   => trim((string) ($data['collected_by'] ?? '')) ?: null,
                'delivered_by'   => $user->id,
                'delivered_at'   => now(),
            ]);

            // Dr Receivable, Cr Repair Service Income + Repair Parts Sales
            if ($total > 0) {
                $this->finance->postRepairInvoice($job, (float) $job->service_charge, (float) $job->parts_total, $discount);
                $this->ledger->customerEntry(['customer_id' => $job->customer_id, 'date' => now()->toDateString(), 'transaction_type' => 'Repair Invoice',
                    'reference_no' => $job->job_no, 'debit' => $total, 'note' => "Repair {$job->job_no}: {$job->device_model}"]);
            }
            if ($amount > 0) {
                $this->pay($job, $user, RepairPayment::PAYMENT, $amount, $method, $data['payment_reference'] ?? null,
                    ($data['account_id'] ?? null) ? (int) $data['account_id'] : null);
            }
            if ($due < 0) {
                // advance bill er cheye beshi: baki ta ferot
                $this->pay($job, $user, RepairPayment::REFUND, -$due, $method, null, ($data['account_id'] ?? null) ? (int) $data['account_id'] : null,
                    null, 'Advance more than the bill');
            }

            $this->event($job, RepairJob::DELIVERED, $user, 'Bill ' . number_format($total, 2) . ', due ' . number_format(max(0, $job->fresh()->due()), 2)
                . ($days ? ", warranty {$days} days" : ', no warranty') . ($job->collected_by ? ", collected by {$job->collected_by}" : ''));
            AuditLogger::log('repair.delivered', $job, null, $job->only(['job_no', 'total', 'paid_amount', 'warranty_until']));
            $this->notifyCustomer($job->refresh(), 'repair_delivered');

            return $job->refresh();
        });
    }

    /**
     * Delivery r age batil: approved part stock e ferot (journal reverse), chaoa part bad, cancel charge (thakle)
     * invoice hoy, baki advance ferot.
     */
    public function cancel(RepairJob $job, User $user, string $reason, float $charge = 0, string $refundMethod = 'Cash'): RepairJob
    {
        return DB::transaction(function () use ($job, $user, $reason, $charge, $refundMethod) {
            $job = RepairJob::lockForUpdate()->findOrFail($job->id);
            if (!$job->isOpen()) {
                throw new \Exception("Job {$job->job_no} is {$job->statusLabel()} and cannot be cancelled.");
            }
            if (trim($reason) === '') {
                throw new \Exception('Write why the job is cancelled.');
            }
            $charge = $this->money($charge, 'Cancellation charge');
            $from = $job->status;

            foreach ($job->parts()->lockForUpdate()->get() as $part) {
                if ($part->status === RepairJobPart::APPROVED) {
                    $this->restock($job, $part, $user, "Job cancelled: {$reason}");
                } elseif ($part->status === RepairJobPart::REQUESTED) {
                    $part->update(['status' => RepairJobPart::REJECTED, 'approved_by' => $user->id]);
                }
            }
            $job->refresh();

            $job->update(['status' => RepairJob::CANCELLED, 'cancel_reason' => $reason, 'cancellation_charge' => $charge, 'total' => $charge,
                'discount' => 0, 'cancelled_by' => $user->id, 'cancelled_at' => now()]);
            if ($charge > 0) {
                $this->finance->postRepairInvoice($job, $charge, 0, 0);
                $this->ledger->customerEntry(['customer_id' => $job->customer_id, 'date' => now()->toDateString(), 'transaction_type' => 'Repair Cancellation Charge',
                    'reference_no' => $job->job_no, 'debit' => $charge, 'note' => "Repair {$job->job_no} cancelled: {$reason}"]);
            }
            $refund = round((float) $job->paid_amount - $charge, 2);
            if ($refund > 0) {
                $this->pay($job, $user, RepairPayment::REFUND, $refund, $refundMethod, null, null, null, "Job cancelled: {$reason}");
            }

            $this->event($job, RepairJob::CANCELLED, $user, $reason . ($charge > 0 ? ' (charge ' . number_format($charge, 2) . ')' : '')
                . ($refund > 0 ? ', refunded ' . number_format($refund, 2) : ''));
            AuditLogger::log('repair.cancelled', $job, ['status' => $from], ['status' => RepairJob::CANCELLED, 'reason' => $reason, 'charge' => $charge, 'refund' => max(0, $refund)]);

            return $job->refresh();
        });
    }

    // ---- report -----------------------------------------------------------------------------------

    /**
     * Service charge (labour) ar parts (bikri, kena, margin) alada; technician onujayi. Delivery/cancel er tarikh dhore.
     */
    public function report(string $from, string $to): array
    {
        $delivered = RepairJob::with('technician:id,name')->where('status', RepairJob::DELIVERED)
            ->whereDate('delivered_at', '>=', $from)->whereDate('delivered_at', '<=', $to)->get();
        $cancelled = RepairJob::where('status', RepairJob::CANCELLED)
            ->whereDate('cancelled_at', '>=', $from)->whereDate('cancelled_at', '<=', $to)->get();

        $sum = fn (Collection $jobs) => [
            'jobs'           => $jobs->count(),
            'service_charge' => round($jobs->sum(fn ($j) => (float) $j->service_charge), 2),
            'parts_sales'    => round($jobs->sum(fn ($j) => (float) $j->parts_total), 2),
            'parts_cost'     => round($jobs->sum(fn ($j) => (float) $j->parts_cost), 2),
            'parts_margin'   => round($jobs->sum(fn ($j) => $j->partsMargin()), 2),
            'discount'       => round($jobs->sum(fn ($j) => (float) $j->discount), 2),
            'billed'         => round($jobs->sum(fn ($j) => (float) $j->total), 2),
            'gross_profit'   => round($jobs->sum(fn ($j) => (float) $j->total - (float) $j->parts_cost), 2),
            'due'            => round($jobs->sum(fn ($j) => max(0, $j->due())), 2),
        ];

        return [
            'totals'        => $sum($delivered),
            'by_technician' => $delivered->groupBy(fn ($j) => $j->technician->name ?? 'Unassigned')->map($sum)->sortKeys()->all(),
            'cancelled'     => ['jobs' => $cancelled->count(), 'charges' => round($cancelled->sum(fn ($j) => (float) $j->cancellation_charge), 2)],
            'open'          => ['jobs' => RepairJob::open()->count(), 'advance' => round((float) RepairJob::open()->sum('paid_amount'), 2)],
        ];
    }

    /**
     * Technician er board: nijer job (ar kauke na deya notun job), dhap onujayi. QC fail = abar kaj e, QC note ache kintu pass hoy ni.
     */
    public function board(User $user): array
    {
        $jobs = RepairJob::with('customer:id,name,phone')
            ->withCount(['parts as pending_parts' => fn ($q) => $q->where('status', RepairJobPart::REQUESTED)])
            ->accessibleBy($user)
            ->whereIn('status', [RepairJob::RECEIVED, RepairJob::IN_REPAIR, RepairJob::WAITING_PARTS, RepairJob::COMPLETED])
            ->where(fn ($q) => $q->where('technician_id', $user->id)
                ->orWhere(fn ($w) => $w->whereNull('technician_id')->where('status', RepairJob::RECEIVED)))
            ->orderByRaw('estimated_delivery IS NULL')->orderBy('estimated_delivery')->orderBy('id')
            ->get();

        $today = now()->toDateString();
        $qcFailed = fn (RepairJob $j) => in_array($j->status, RepairJob::WORKING, true) && $j->qc_note && !$j->qc_by;

        return [
            'new'         => $jobs->where('status', RepairJob::RECEIVED)->values(),
            'working'     => $jobs->filter(fn ($j) => in_array($j->status, RepairJob::WORKING, true) && !$qcFailed($j))->values(),
            'qc_failed'   => $jobs->filter($qcFailed)->values(),
            'awaiting_qc' => $jobs->where('status', RepairJob::COMPLETED)->values(),
            'overdue'     => $jobs->filter(fn ($j) => $j->estimated_delivery && $j->estimated_delivery->toDateString() < $today)->values(),
            'counts'      => [
                'new'           => $jobs->where('status', RepairJob::RECEIVED)->count(),
                'in_repair'     => $jobs->where('status', RepairJob::IN_REPAIR)->count(),
                'waiting_parts' => $jobs->where('status', RepairJob::WAITING_PARTS)->count(),
                'qc_failed'     => $jobs->filter($qcFailed)->count(),
                'awaiting_qc'   => $jobs->where('status', RepairJob::COMPLETED)->count(),
                'overdue'       => $jobs->filter(fn ($j) => $j->estimated_delivery && $j->estimated_delivery->toDateString() < $today)->count(),
                'done_month'    => RepairJob::where('technician_id', $user->id)->whereIn('status', [RepairJob::QC_PASSED, RepairJob::DELIVERED])
                    ->where('completed_at', '>=', now()->startOfMonth())->count(),
            ],
        ];
    }

    // Repair korte pare emon user (technician list)
    public function technicians(): Collection
    {
        return User::orderBy('name')->get()->filter(fn (User $u) => $u->isActive() && $u->can('work repair'))->values();
    }

    // ---- helpers ------------------------------------------------------------------------------------

    private function customer(array $data, User $user): Customer
    {
        if (!empty($data['customer_id'])) {
            $customer = Customer::findOrFail($data['customer_id']);
            if (!$customer->isActive()) {
                throw new \Exception("{$customer->name} is inactive.");
            }
            return $customer;
        }

        $name = trim((string) ($data['new_customer_name'] ?? ''));
        $phone = trim((string) ($data['new_customer_phone'] ?? ''));
        if ($name === '' || $phone === '') {
            throw new \Exception('Select the customer, or enter the new customer name and phone.');
        }
        $existing = Customer::where('phone', $phone)->orWhere('phone_normalized', Customer::normalizePhone($phone))->first();
        if ($existing) {
            throw new \Exception("Phone {$phone} belongs to customer {$existing->name}. Select that customer.");
        }

        $customer = Customer::create(['name' => $name, 'phone' => $phone, 'status' => 1, 'created_by' => $user->id]);
        AuditLogger::log('customer.created', $customer, null, $customer->only(['name', 'phone']) + ['via' => 'repair']);

        return $customer;
    }

    private function technician(int $id): User
    {
        $user = User::find($id);
        if (!$user || !$user->isActive() || !$user->can('work repair')) {
            throw new \Exception('Select an active technician.');
        }

        return $user;
    }

    // Assigned technician, ba approve permission thaka keu
    private function assertWorker(RepairJob $job, User $user): void
    {
        if ($user->can('approve repair')) {
            return;
        }
        if (!$user->can('work repair')) {
            throw new \Exception('You are not allowed to work on repairs.');
        }
        if ($job->technician_id && (int) $job->technician_id !== $user->id) {
            throw new \Exception("This job is assigned to {$job->technician?->name}.");
        }
    }

    private function lock(RepairJob $job, array $allowed): RepairJob
    {
        $job = RepairJob::lockForUpdate()->findOrFail($job->id);
        if (!in_array($job->status, $allowed, true)) {
            throw new \Exception("Job {$job->job_no} is {$job->statusLabel()}; this step is not allowed now.");
        }

        return $job;
    }

    /**
     * Approved part stock e ferot: stock +qty, average e ager kena dam firiye (stock er mot dam mele), journal reverse.
     */
    private function restock(RepairJob $job, RepairJobPart $part, User $user, string $reason): void
    {
        if ($part->status !== RepairJobPart::APPROVED) {
            throw new \Exception("This part is {$part->statusLabel()}, not in the phone.");
        }

        $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($part->main_stock_id);
        $current = max(0, (int) $stock->quantity);
        $oldAvg = (float) $stock->average_purchase_price;
        $stock->average_purchase_price = round((($current * $oldAvg) + $part->lineCost()) / ($current + $part->quantity), 2);
        PriceHistory::record($stock->product, $stock, PriceHistory::AVERAGE_COST, $oldAvg, $stock->average_purchase_price, 'repair_part_return', $job);
        $this->stock->move($stock, $part->quantity, StockService::REPAIR_PART_RETURN, $job, "{$job->job_no}: {$reason}", $job->branch_id, 'repair_part_return:' . $part->id);

        $part->update(['status' => RepairJobPart::RETURNED, 'returned_at' => now()]);
        $job->update([
            'parts_total' => round((float) $job->parts_total - $part->lineTotal(), 2),
            'parts_cost'  => round((float) $job->parts_cost - $part->lineCost(), 2),
        ]);
        $this->finance->reverseRepairPart($part, $reason);
        $this->event($job, 'part_returned', $user, "{$part->quantity} x {$stock->variantLabel()}: {$reason}");
    }

    private function pay(RepairJob $job, User $user, string $type, float $amount, string $method, ?string $reference, ?int $accountId,
                         ?string $token = null, ?string $note = null): RepairPayment
    {
        $channel = SalePaymentService::assertMethod($method, $reference);
        if ($channel === 'credit') {
            throw new \Exception('Store credit cannot be used for repairs.');
        }
        $refund = $type === RepairPayment::REFUND;

        $entry = $this->ledger->customerEntry([
            'customer_id'      => $job->customer_id,
            'date'             => now()->toDateString(),
            'transaction_type' => 'Repair ' . RepairPayment::LABELS[$type],
            'reference_no'     => $job->job_no,
            'debit'            => $refund ? $amount : 0,
            'credit'           => $refund ? 0 : $amount,
            'note'             => "Repair {$job->job_no} ({$method})" . ($note ? ": {$note}" : ''),
        ]);
        $payment = RepairPayment::create([
            'repair_job_id'      => $job->id,
            'customer_id'        => $job->customer_id,
            'type'               => $type,
            'method'             => $method,
            'channel'            => $channel,
            'amount'             => $amount,
            'account_id'         => $accountId,
            'reference'          => trim((string) $reference) ?: null,
            'payment_date'       => now()->toDateString(),
            'customer_ledger_id' => $entry->id,
            'note'               => $note,
            'request_token'      => $token,
            'received_by'        => $user->id,
        ]);
        $this->finance->postRepairPayment($payment, $job);

        $job->update(['paid_amount' => round((float) $job->paid_amount + ($refund ? -$amount : $amount), 2)]);
        $this->event($job, 'payment', $user, RepairPayment::LABELS[$type] . ' ' . number_format($amount, 2) . " ({$method})");

        return $payment;
    }

    // customer ke repair er khobor (protiti dhap e ekbar)
    private function notifyCustomer(RepairJob $job, string $event): void
    {
        app(NotificationService::class)->notify($event, Customer::find($job->customer_id), $job, [
            'job_no' => $job->job_no, 'device' => $job->device_model, 'total' => number_format($job->billTotal(), 2),
            'due' => number_format(max(0, $job->due()), 2), 'warranty_until' => $job->warranty_until?->format('d M Y') ?? 'none',
        ]);
    }

    private function money(float $amount, string $label): float
    {
        $amount = round($amount, 2);
        if ($amount < 0) {
            throw new \Exception("{$label} cannot be negative.");
        }

        return $amount;
    }

    private function event(RepairJob $job, string $event, User $user, ?string $note = null): RepairJobEvent
    {
        return RepairJobEvent::create(['repair_job_id' => $job->id, 'event' => $event, 'note' => $note, 'user_id' => $user->id]);
    }
}
