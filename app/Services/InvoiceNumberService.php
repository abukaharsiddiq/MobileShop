<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;

/**
 * Invoice number save korar somoy (transaction er bhitore) toiri hoy, page kholar somoy na.
 * Settings row lock kora thake, tai duita counter ekshathe save korleo eki number pay na.
 */
class InvoiceNumberService
{
    public function nextSale(): string
    {
        $setting = Setting::lockForUpdate()->firstOrFail();

        return $this->next(Sale::class, $setting->sale_invoice_prefix, $setting->sale_invoice_number);
    }

    public function nextPurchase(): string
    {
        $setting = Setting::lockForUpdate()->firstOrFail();

        return $this->next(Purchase::class, $setting->purchase_invoice_prefix ?: 'PUR', $setting->purchase_invoice_number ?: '1');
    }

    // Page e dekhanor jonno (save er somoy number bodlate pare)
    public function previewSale(): string
    {
        $setting = Setting::first();

        return $setting ? $this->next(Sale::class, $setting->sale_invoice_prefix, $setting->sale_invoice_number) : '';
    }

    public function previewPurchase(): string
    {
        $setting = Setting::first();

        return $setting ? $this->next(Purchase::class, $setting->purchase_invoice_prefix ?: 'PUR', $setting->purchase_invoice_number ?: '1') : '';
    }

    private function next(string $model, string $prefix, string $start): string
    {
        $last = $model::where('invoice_no', 'like', $prefix . '-%')->latest('id')->value('invoice_no');
        $number = $last ? (int) substr($last, strlen($prefix) + 1) + 1 : (int) $start;
        $number = max($number, (int) $start);

        // jodi kono karone number ta already thake, porer ta
        do {
            $invoiceNo = $prefix . '-' . str_pad($number, strlen($start), '0', STR_PAD_LEFT);
            $number++;
        } while ($model::where('invoice_no', $invoiceNo)->exists());

        return $invoiceNo;
    }
}
