<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ekta physical device (phone). Ekoi IMEI er ekadhik record thakte pare (bikri hoye used phone
 * hisebe abar kena hole notun record); purono record ar imei_movements e itihash thake.
 */
class ProductImei extends Model
{
    // Purono man (0, 1, 2) bodlano hoy ni
    const STATUS_SOLD = 0;
    const STATUS_AVAILABLE = 1;
    const STATUS_IN_STOCK = 1;                // purono naam (= available)
    const STATUS_RETURNED_TO_SUPPLIER = 2;
    const STATUS_RESERVED = 3;                // customer er jonno rakha
    const STATUS_IN_REPAIR = 4;
    const STATUS_DAMAGED = 5;
    const STATUS_WRITTEN_OFF = 6;             // haralo/churi/nosto - stock theke bad

    const STATUSES = [
        self::STATUS_AVAILABLE            => 'Available',
        self::STATUS_RESERVED             => 'Reserved',
        self::STATUS_IN_REPAIR            => 'In Repair',
        self::STATUS_DAMAGED              => 'Damaged',
        self::STATUS_SOLD                 => 'Sold',
        self::STATUS_RETURNED_TO_SUPPLIER => 'Returned to Supplier',
        self::STATUS_WRITTEN_OFF          => 'Written Off',
    ];

    // dokaner dokhole (stock quantity te gona hoy, ekoi IMEI arekbar dokhole aste pare na)
    const OWNED_STATUSES = [self::STATUS_AVAILABLE, self::STATUS_RESERVED, self::STATUS_IN_REPAIR, self::STATUS_DAMAGED];

    protected $fillable = [
        'purchase_id',
        'sale_id',
        'sale_detail_id',
        'product_id',
        'main_stock_id',
        'branch_id',
        'imei_no',
        'imei_no_2',
        'status',
        'reserved_customer_id',
        'status_note',
        'status_changed_at',
    ];

    protected function casts(): array
    {
        return ['status_changed_at' => 'datetime'];
    }

    // Note: active_imei database er generated column (status=1 hole imei_no). Eta fillable na.
    // Dokhole thaka shob status er jonno duita slot mile unique: product_imei_numbers.active_imei.

    protected static function booted(): void
    {
        // product_imei_numbers (IMEI 1 + IMEI 2 er unique index) protiti poriborton e milano
        static::saved(function (ProductImei $imei) {
            if ($imei->wasRecentlyCreated || $imei->wasChanged(['imei_no', 'imei_no_2', 'status'])) {
                $imei->syncNumbers();
            }
        });
    }

    public function syncNumbers(): void
    {
        $active = $this->isOwned();

        foreach ([1 => $this->imei_no, 2 => $this->imei_no_2] as $slot => $number) {
            if ($number) {
                ProductImeiNumber::updateOrCreate(
                    ['product_imei_id' => $this->id, 'slot' => $slot],
                    ['imei_no' => $number, 'is_active' => $active]
                );
            } else {
                ProductImeiNumber::where('product_imei_id', $this->id)->where('slot', $slot)->delete();
            }
        }
    }

    public function isOwned(): bool
    {
        return in_array((int) $this->status, self::OWNED_STATUSES, true);
    }

    // ei customer er kache bikri kora jay: available, ba tar jonno reserved
    public function isSellableTo(?int $customerId): bool
    {
        return (int) $this->status === self::STATUS_AVAILABLE
            || ((int) $this->status === self::STATUS_RESERVED && $customerId && (int) $this->reserved_customer_id === $customerId);
    }

    public function scopeOwned($query)
    {
        return $query->whereIn('product_imeis.status', self::OWNED_STATUSES);
    }

    // IMEI 1 ba IMEI 2 diye khoja
    public function scopeWhereNumber($query, string $number)
    {
        return $query->where(fn ($q) => $q->where('imei_no', $number)->orWhere('imei_no_2', $number));
    }

    // Asol IMEI: 15 digit ar shesh digit Luhn checksum
    public static function isValidFormat($imei)
    {
        if (!preg_match('/^\d{15}$/', (string) $imei)) {
            return false;
        }

        // 000000000000000, 111111111111111 ... Luhn pass kore kintu asol IMEI na
        if (count(array_unique(str_split($imei))) == 1) {
            return false;
        }

        $sum = 0;
        foreach (str_split(strrev($imei)) as $i => $digit) {
            $d = (int) $digit;
            if ($i % 2 == 1) {
                $d *= 2;
                if ($d > 9) $d -= 9;
            }
            $sum += $d;
        }

        return $sum % 10 == 0;
    }

    public function movements()
    {
        return $this->hasMany(ImeiMovement::class, 'imei_no', 'imei_no')->orderBy('id');
    }

    public function numbers()
    {
        return $this->hasMany(ProductImeiNumber::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function reservedCustomer()
    {
        return $this->belongsTo(Customer::class, 'reserved_customer_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    // User je branch er device dhorte pare (branch chara purono record shobar jonno khola)
    public function scopeAccessibleBy($query, User $user)
    {
        $branchIds = $user->allowedBranchIds();
        if ($branchIds === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id'));
    }

    // "111, 222\n333" -> ['111', '222', '333'] (dual-SIM "111/222" ekta token thake)
    public static function parseList($text)
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $text))));
    }

    /**
     * Protiti device ekta line/token: "IMEI1" ba dual-SIM "IMEI1/IMEI2".
     * @return array<int, array{0: string, 1: ?string, 2: int}> [IMEI 1, IMEI 2, koyta number dilo]
     */
    public static function parseDevices($text): array
    {
        return array_map(function ($token) {
            $parts = array_values(array_filter(array_map('trim', explode('/', $token)), 'strlen'));

            return [$parts[0] ?? '', $parts[1] ?? null, count($parts)];
        }, self::parseList($text));
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }

    public static function statusLabel($status): string
    {
        return self::STATUSES[(int) $status] ?? 'Unknown';
    }

    public function statusText()
    {
        return self::statusLabel($this->status);
    }

    public function statusBadge(): string
    {
        return match ((int) $this->status) {
            self::STATUS_AVAILABLE => 'success',
            self::STATUS_RESERVED, self::STATUS_IN_REPAIR => 'info',
            self::STATUS_DAMAGED, self::STATUS_RETURNED_TO_SUPPLIER => 'warning',
            self::STATUS_WRITTEN_OFF => 'dark',
            default => 'danger',
        };
    }

    // "356938035643809 / 490154203237518"
    public function numbersLabel(): string
    {
        return $this->imei_no . ($this->imei_no_2 ? ' / ' . $this->imei_no_2 : '');
    }

    // Relationship to Product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function saleDetail() {
       return $this->belongsTo(SaleDetail::class, 'sale_detail_id');
    }

}
