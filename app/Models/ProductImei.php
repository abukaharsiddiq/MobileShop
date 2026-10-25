<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImei extends Model
{
    const STATUS_SOLD = 0;
    const STATUS_IN_STOCK = 1;
    const STATUS_RETURNED_TO_SUPPLIER = 2;

    protected $fillable = [
        'purchase_id',
        'sale_id',
        'sale_detail_id',
        'product_id',
        'main_stock_id',
        'branch_id',
        'imei_no',
        'status',
    ];

    // Note: active_imei database er generated column (status=1 hole imei_no). Eta fillable na,
    // app theke lekha jay na; ekoi IMEI ekbar-er beshi active hote dey na.

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

    public function branch()
    {
        return $this->belongsTo(Branch::class);
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

    // "111, 222\n333" -> ['111', '222', '333']
    public static function parseList($text)
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $text))));
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }

    public function statusText()
    {
        return match ((int) $this->status) {
            self::STATUS_IN_STOCK => 'Available in Stock',
            self::STATUS_RETURNED_TO_SUPPLIER => 'Returned to Supplier',
            default => 'Sold Out',
        };
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
