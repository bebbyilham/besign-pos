<?php

namespace App\Models\Tenants;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * @mixin IdeHelperProduct
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'hero_images_url', 'expired'];

    protected $appends = ['hero_image'];

    private int $expiredDay = 20;

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class)
            ->where('is_ready', 1);
    }

    public function CartItems(): HasMany
    {
        return $this->hasMany(CartItem::class)
            ->where('user_id', Filament::auth()->id());
    }

    /** 
     * Relasi untuk ambil stok masuk terakhir 
     */
    public function lastStockIn()
    {
        return $this->hasOne(Stock::class)
            ->where('type', 'in')
            ->latest('date'); // bisa ganti created_at jika lebih cocok
    }

    public function scopeStockLatestCalculateIn()
    {
        $usingFifoPrice = Setting::get('selling_method', env('SELLING_METHOD', 'fifo')) == 'fifo';
        $usingNormalPrice = Setting::get('selling_method', env('SELLING_METHOD', 'fifo')) == 'normal';
        $usingLifoPrice = Setting::get('selling_method', env('SELLING_METHOD', 'fifo')) == 'lifo';

        return $this
            ->stocks()
            ->where('type', 'in')
            ->when($usingNormalPrice, fn(Builder $query) => $query->orderBy('date')->latest())
            ->when($usingFifoPrice, fn(Builder $query) => $query
                ->where('stock', '>', 0)
                ->orderBy('created_at')->orderBy('date'))
            ->when($usingLifoPrice, fn(Builder $query) => $query
                ->where('stock', '>', 0)
                ->orderByDesc('created_at')->orderByDesc('date'));
    }

    /** 
     * Stock total berdasarkan metode
     */
    public function stockCalculate(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->stockLatestCalculateIn()->sum('stock'),
            set: fn($value) => $value
        );
    }

    /** =======================
     * HARGA MODAL (Initial Price)
     * ========================*/

    // Versi metode (normal = last stock, fifo/lifo = scope)
    public function initialPriceCalculate(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $method = Setting::get('selling_method', env('SELLING_METHOD', 'fifo'));

                if ($method === 'normal') {
                    return $this->lastStockIn()->first()?->initial_price ?? $value;
                }

                $stock = $this->stockLatestCalculateIn();
                if ($stock?->first() == null) {
                    return $value;
                }

                return $stock->first()->initial_price;
            },
            set: fn($value) => $value
        );
    }

    // Versi stok terakhir langsung
    public function initialPriceLastStock(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $this->lastStockIn()->first()?->initial_price ?? $value,
            set: fn($value) => $value
        );
    }

    /** =======================
     * HARGA JUAL (Selling Price)
     * ========================*/

    // Versi metode (normal = last stock, fifo/lifo = scope)
    public function sellingPriceCalculate(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $method = Setting::get('selling_method', env('SELLING_METHOD', 'fifo'));

                if ($method === 'normal') {
                    return $this->lastStockIn()->first()?->selling_price ?? $value;
                }

                $stock = $this->stockLatestCalculateIn();
                if ($stock?->first() == null) {
                    return $value;
                }

                return $stock->first()->selling_price;
            },
            set: fn($value) => $value
        );
    }

    // Versi stok terakhir langsung
    public function sellingPriceLastStock(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $this->lastStockIn()->first()?->selling_price ?? $value,
            set: fn($value) => $value
        );
    }

    /** =======================
     * Lain-lain
     * ========================*/

    public function sellingPriceLabelCalculate(): Attribute
    {
        return Attribute::make(
            get: fn($value) => Number::currency($this->initial_price, Setting::get('currency', 'IDR')),
            set: fn($value) => $value
        );
    }

    public function heroImages(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $value ? Str::of($value)->explode(',') : [],
            set: fn($value) => $value ? Arr::join(is_array($value) ? $value : $value->toArray(), ',') : null
        );
    }

    public function netProfit(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->selling_price - $this->initial_price
        );
    }

    public function scopeNearestExpiredProduct(Builder $builder)
    {
        return $builder->whereHas('stocks', function (Builder $builder) {
            $nearestExpired = now()->addDay($this->expiredDay);

            return $builder->whereDate('expired', '<=', $nearestExpired);
        });
    }

    public function expiredStock(): Attribute
    {
        return Attribute::make(
            get: function () {
                $nearestExpired = now()->addDay($this->expiredDay);

                return $this
                    ->stocks()
                    ->where('stock', '>', 0)
                    ->whereDate('expired', '<=', $nearestExpired)->latest()->first();
            }
        );
    }

    public function hasExpiredStock(): Attribute
    {
        return Attribute::make(
            get: function () {
                $nearestExpired = now()->addDay($this->expiredDay);

                return $this->stocks()
                    ->where('stock', '>', 0)
                    ->whereDate('expired', '<=', $nearestExpired)->exists();
            }
        );
    }

    public function setExpiredDay(int $day)
    {
        $this->expiredDay = $day;
        return $this;
    }

    public function heroImage(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->hero_images ? $this->hero_images[0] : 'https://cdn4.iconfinder.com/data/icons/picture-sharing-sites/32/No_Image-1024.png'
        );
    }

    public function sellingDetails(): HasMany
    {
        return $this->hasMany(SellingDetail::class);
    }

    public function scopeInActivate(Builder $builder): Builder
    {
        return $builder->where('show', false);
    }

    public function priceUnits(): HasMany
    {
        return $this->hasMany(PriceUnit::class);
    }
}
