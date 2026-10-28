<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'type',           // mobile / accessory
        'dual_sim',       // IMEI 1 + IMEI 2
        'product_name',
        'model_number',
        'barcode',
        'category_id',
        'brand_id',
        'ram_id',
        'rom_id',
        'color_id',
        'purchase_price',
        'sale_price',
        'low_stock_threshold',
        'warranty_months',
        'brand_warranty_months',
        'description',
        'unit',
        'status',
    ];

    // Product shudhu model master; stock protiti variant (main_stocks) e alada.
    // opening_quantity purono column, ar lekha hoy na (stock purchase/adjustment diye ashe).
    // ram_id/rom_id/color_id = default variant (notun purchase form e age theke bacha thake).

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'dual_sim' => 'boolean',
            'low_stock_threshold' => 'integer',
            'warranty_months' => 'integer',
            'brand_warranty_months' => 'integer',
        ];
    }

    public function variants()
    {
        return $this->hasMany(MainStock::class);
    }

    // default variant (product er RAM/ROM/Color) er stock row
    public function defaultVariant()
    {
        return $this->variants()
            ->where(fn ($q) => $this->ram_id ? $q->where('ram_id', $this->ram_id) : $q->whereNull('ram_id'))
            ->where(fn ($q) => $this->rom_id ? $q->where('rom_id', $this->rom_id) : $q->whereNull('rom_id'))
            ->where(fn ($q) => $this->color_id ? $q->where('color_id', $this->color_id) : $q->whereNull('color_id'))
            ->first();
    }

    // shob variant er stock jog (product e alada kore rakha hoy na)
    public function totalStock(): int
    {
        return (int) ($this->variants_sum_quantity ?? $this->variants()->sum('quantity'));
    }


    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function ram()
    {
        return $this->belongsTo(Ram::class);
    }

    public function rom()
    {
        return $this->belongsTo(Rom::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    public function imeis()
{
    return $this->hasMany(ProductImei::class, 'product_id', 'id');
}

    const TYPES = [
        'smart_phone'       => 'Smart Phone',
        'used_phone'        => 'Used Smart Phone',
        'refurbished_phone' => 'Refurbished Smart Phone',
        'button_phone'      => 'Button Phone',
        'accessory'         => 'Accessories',
        'spare_part'        => 'Spare Parts',
    ];

    // phone na (IMEI, RAM/storage, dual-SIM hoy na); spare part repair e lage, POS eo becha jay
    const NON_DEVICE_TYPES = ['accessory', 'spare_part'];

    // IMEI chara kena-becha hobe na
    const IMEI_REQUIRED_TYPES = ['smart_phone', 'used_phone', 'refurbished_phone'];

    // Bikri hoye jawa IMEI abar kena jay shudhu ei type e (purono phone hisebe)
    const SECOND_HAND_TYPES = ['used_phone', 'refurbished_phone'];

    public function typeLabel()
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    // Smart phone (notun, used, refurbished) kena-becha IMEI chara hobe na
    // Smart phone e shob shomoy; button phone e dokaner setting (config/imei.php) onujayi
    public function requiresImei()
    {
        return in_array($this->type, self::IMEI_REQUIRED_TYPES)
            || ($this->type === 'button_phone' && config('imei.button_phone_required'));
    }

    // dual-SIM model e IMEI 2 lagbe; single SIM e IMEI 2 deya jay na
    public function requiresSecondImei(): bool
    {
        return $this->allowsImei() && (bool) $this->dual_sim;
    }

    public function isSecondHand()
    {
        return in_array($this->type, self::SECOND_HAND_TYPES);
    }

    // Accessories/spare part er IMEI thake na; button phone er IMEI oichchhik
    public function allowsImei()
    {
        return !in_array($this->type, self::NON_DEVICE_TYPES, true);
    }

    // Smart phone (notun/used/refurbished) er variant e RAM ar storage lagbe
    public static function typeRequiresMemory(?string $type): bool
    {
        return in_array($type, self::IMEI_REQUIRED_TYPES, true);
    }

    // Accessories/spare part er RAM/storage hoy na (color hote pare)
    public static function typeAllowsMemory(?string $type): bool
    {
        return !in_array($type, self::NON_DEVICE_TYPES, true);
    }

    public function isActive(): bool
    {
        return (bool) $this->status;
    }
}
