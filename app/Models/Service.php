<?php

namespace App\Models;

use App\Classes\Price;
use App\Models\Traits\HasProperties;
use App\Observers\ServiceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy([ServiceObserver::class])]
class Service extends Model
{
    use HasFactory, HasProperties;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'order_id',
        'product_id',
        'plan_id',
        'quantity',
        'price',
        'expires_at',
        'subscription_id',
        'status',
        'coupon_id',
    ];

    protected $casts = [
        'expires_at' => 'date',
    ];

    /**
     * Get the order that owns the service.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the coupon that owns the service.
     */
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function currency(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->order->currency
        );
    }

    /**
     * Price of the service.
     *
     * @return string
     */
    public function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->price * $this->quantity, 'currency' => $this->order->currency])
        );
    }

    /**
     * Get the description for the next invoice item.
     */
    public function description(): Attribute
    {
        if ($this->plan->type == 'free' || $this->plan->type == 'one-time') {
            return Attribute::make(
                get: fn () => $this->product->name
            );
        }
        $date = $this->expires_at ?? now();
        $endDate = $date->{'add' . ucfirst($this->plan->billing_unit) . 's'}($this->plan->billing_period);

        return Attribute::make(
            get: fn () => $this->product->name . ' (' . $date->format('M d, Y') . ' - ' . $endDate->format('M d, Y') . ')'
        );
    }

    /**
     * Calculate next due date.
     */
    public function calculateNextDueDate()
    {
        if ($this->plan->type == 'onetime' || $this->plan->type == 'free') {
            return null;
        }
        $date = $this->expires_at ?? now();

        return $date->{'add' . ucfirst($this->plan->billing_unit) . 's'}($this->plan->billing_period);
    }

    /**
     * Get the product corresponding to the service.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the plan corresponding to the service.
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the service's configurations.
     */
    public function configs()
    {
        return $this->hasMany(ServiceConfig::class);
    }

    /**
     * Get invoiceItems
     */
    public function invoiceItems()
    {
        return $this->morphMany(InvoiceItem::class, 'reference');
    }

    /**
     * Get invoices
     */
    public function invoices()
    {
        return $this->hasManyThrough(Invoice::class, InvoiceItem::class, 'reference_id', 'id', 'id', 'invoice_id')->where('reference_type', Service::class);
    }

    public function cancellable(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status !== 'cancelled' && $this->plan->type != 'free' && $this->plan->type != 'onetime'
        );
    }

    public function upgradable(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->product->upgrades()->count() > 0 && $this->status == 'active' && $this->upgrade !== null && !$this->upgrade->exists()
        );
    }

    public function upgrades()
    {
        return $this->product->upgrades->filter(function ($product) {
            $plan = $product->plans()->where('billing_unit', $this->plan->billing_unit)->where('billing_period', $this->plan->billing_period)->get();
            // Only get the upgrades that have the exact same billing cycle as the service
            if ($plan->count() > 0) {
                $product->plan = $plan->first();

                return $product;
            }

            return null;
        });
    }

    public function upgrade()
    {
        return $this->hasOne(ServiceUpgrade::class);
    }
}