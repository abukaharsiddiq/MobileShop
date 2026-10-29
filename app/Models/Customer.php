<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    const TYPES = [
        'retail'    => 'Retail',
        'wholesale' => 'Wholesale',
        'corporate' => 'Corporate',
    ];

    protected $fillable = [
        'name',
        'phone',
        'alt_phone',
        'email',
        'nid_no',
        'customer_type',
        'address',
        'note',
        'opening_balance',
        'status',
        'created_by',
        'notify_sms',        // lenden er SMS (shommoti)
        'notify_whatsapp',   // WhatsApp - customer raji hole
    ];

    protected function casts(): array
    {
        return ['notify_sms' => 'boolean', 'notify_whatsapp' => 'boolean'];
    }

    protected static function booted(): void
    {
        // duplicate khojar jonno normalized phone shob shomoy mile thake
        static::saving(function (Customer $customer) {
            $customer->phone_normalized = self::normalizePhone($customer->phone);
            $customer->alt_phone_normalized = self::normalizePhone($customer->alt_phone);
        });
    }

    /**
     * "01711-000000", "+880 1711 000000", "8801711000000" -> "01711000000". Bangladesh mobile na hole shudhu digit.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (preg_match('/^(?:00)?880?(1\d{9})$/', $digits, $m)) {
            return '0' . $m[1];
        }

        return $digits !== '' ? $digits : null;
    }

    // ei number (phone ba alternate phone) onno kono customer er?
    public static function findByPhone(?string $phone, ?int $exceptId = null)
    {
        $normalized = self::normalizePhone($phone);
        if (!$normalized) {
            return collect();
        }

        return self::where(fn ($q) => $q->where('phone_normalized', $normalized)->orWhere('alt_phone_normalized', $normalized))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->get();
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function ledgers()
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function returns()
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function installments()
    {
        return $this->hasMany(Installment::class);
    }

    // list e protita customer er ledger balance ek query te (debit - credit: + due, - advance)
    public function scopeWithBalance($query)
    {
        return $query->addSelect(['ledger_balance' => CustomerLedger::selectRaw('COALESCE(SUM(debit - credit), 0)')
            ->whereColumn('customer_id', 'customers.id')]);
    }

    // naam, phone (jekono format), alternate phone, NID, email
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $phone = self::normalizePhone($term);

        return $query->where(function ($q) use ($term, $phone) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('alt_phone', 'like', "%{$term}%")
                ->orWhere('nid_no', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
            if ($phone && strlen($phone) >= 4) {
                $q->orWhere('phone_normalized', 'like', "%{$phone}%")->orWhere('alt_phone_normalized', 'like', "%{$phone}%");
            }
        });
    }

    public function isActive(): bool
    {
        return (int) $this->status === 1;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->customer_type] ?? ucfirst((string) $this->customer_type);
    }
}
