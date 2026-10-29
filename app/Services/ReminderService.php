<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\NotificationChannel;
use App\Models\Warranty;
use Carbon\Carbon;

/**
 * Prottekdin (scheduler) er reminder: kisti due (age theke ekbar, overdue hole protii N dine ekbar) ar warranty
 * shesh (ekbar). Ekoi dhap e duibar na - NotificationService er dedupe; tai bar bar chalano nirapod.
 */
class ReminderService
{
    public function __construct(
        private NotificationService $notifications,
        private InstallmentService $installments,
        private WarrantyService $warranties,
    ) {}

    // fere: koyta message toiri holo
    public function installments(): int
    {
        if (!NotificationChannel::enabled()) {
            return 0;   // SMS/WhatsApp bondho
        }
        $today = now()->startOfDay();
        $until = $today->copy()->addDays((int) config('notifications.installment_days_before', 2))->toDateString();
        $every = max(1, (int) config('notifications.installment_overdue_every_days', 7));
        $created = 0;

        // chunkById nije id onujayi sajay (onno orderBy dile row bad pore)
        Installment::with(['customer', 'sale:id,invoice_no'])->open()->where('due_date', '<=', $until)
            ->chunkById(200, function ($rows) use ($today, $every, &$created) {
                foreach ($rows as $installment) {
                    $due = Carbon::parse($installment->due_date);
                    // age: due date er jonno ekbar; overdue: protii $every dine ekbar
                    $stage = $due->gte($today) ? 'due-' . $due->toDateString() : 'overdue-' . intdiv($installment->daysOverdue(), $every);
                    $messages = $this->notifications->notify('installment_due', $installment->customer, $installment, [
                        'invoice' => $installment->sale->invoice_no ?? '', 'installment_no' => $installment->installment_no,
                        'amount' => number_format($installment->openAmount(), 2), 'due_date' => $due->format('d M Y'),
                    ], $stage);
                    if ($messages->contains(fn ($m) => $m->status !== 'skipped')) {
                        $this->installments->markReminded($installment, 'sms');
                    }
                    $created += $messages->count();
                }
            });

        return $created;
    }

    public function warranties(): int
    {
        if (!NotificationChannel::enabled()) {
            return 0;
        }
        $today = now()->toDateString();
        $until = now()->addDays((int) config('notifications.warranty_days_before', 7))->toDateString();
        $created = 0;

        Warranty::with(['customer', 'product:id,product_name'])->inForce()->whereBetween('end_date', [$today, $until])
            ->whereDoesntHave('openClaim')
            ->chunkById(200, function ($rows) use (&$created) {
                foreach ($rows as $warranty) {
                    $messages = $this->notifications->notify('warranty_expiry', $warranty->customer, $warranty, [
                        'product' => $warranty->product->product_name ?? '', 'type' => $warranty->type, 'warranty_no' => $warranty->warranty_no,
                        'end_date' => $warranty->end_date->format('d M Y'),
                    ], 'expiry');
                    if ($messages->contains(fn ($m) => $m->status !== 'skipped')) {
                        $this->warranties->markReminded($warranty, 'sms');
                    }
                    $created += $messages->count();
                }
            });

        return $created;
    }
}
