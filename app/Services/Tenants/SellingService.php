<?php

namespace App\Services\Tenants;

use App\Events\RecalculateEvent;
use App\Events\SellingCreated;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\PriceUnit;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Services\Tenants\Traits\HasNumber;
use App\Services\VoucherService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class SellingService
{
    use HasNumber;

    /**
     * Creates a new selling record and dispatches related events.
     *
     * @param array $data The data for creating the selling record.
     * @return Selling The created Selling model instance.
     * @throws Exception If any error occurs during the transaction.
     */
    public function create(array $data)
    {
        try {
            // Begin database transaction to ensure atomicity
            DB::beginTransaction();

            /** @var Selling $selling Create the selling record */
            $selling = Selling::create($data);

            // Dispatch event after selling is created
            SellingCreated::dispatch($selling, $data);

            /**
             * @var Collection<Product> $products Find products related to the selling details
             * This assumes sellingDetails is a relationship on the Selling model.
             */
            $products = Product::find($selling->sellingDetails->pluck('product_id'));
            // Dispatch recalculation event for the involved products
            RecalculateEvent::dispatch($products, $data);

            // Commit the transaction if all operations are successful
            DB::commit();

            return $selling;
        } catch (Exception $e) {
            // Rollback the transaction if an error occurs
            DB::rollBack();
            // Re-throw the exception for upstream handling
            throw $e;
        }
    }

    /**
     * Maps and processes product request data for selling.
     * Calculates total price, cost, discounts, taxes, and money changes.
     *
     * @param array $data The raw product request data.
     * @return array The processed and mapped request data.
     */
    public function mapProductRequest(array $data): array
    {
        // Apply default tax if not already set and a default exists
        if (Setting::get('default_tax', 0) != 0 && !isset($data['tax'])) {
            $data['tax'] = Setting::get('default_tax');
        }

        $request = [];
        // Ensure payed_money is a float, defaulting to 0
        $payed_money = (float) ($data['payed_money'] ?? 0);

        // Check if 'friend_price' is not set or is explicitly false
        if (isset($data['friend_price']) && !$data['friend_price']) {
            $total_price = 0.0;
            $total_price_after_discount = 0.0; // This variable is calculated but not used in the final $request
            $total_discount_per_item = 0.0;
            $total_cost = 0.0;

            $productsCollection = collect($data['products']);

            // Iterate through products to calculate totals
            $productsCollection->each(
                function ($product) use (&$total_price, &$total_cost, &$total_price_after_discount, &$total_discount_per_item) {
                    // If a specific price_unit_id is provided, use its selling_price
                    if (isset($product['price_unit_id']) && $product['price_unit_id'] != null) {
                        $priceUnit = PriceUnit::whereId($product['price_unit_id'])->first();
                        // Ensure priceUnit exists before accessing its properties
                        if ($priceUnit) {
                            $product['price'] = (float) $priceUnit->selling_price * (int) $product['qty'];
                        } else {
                            // Handle case where PriceUnit is not found, e.g., log error or set default price
                            $product['price'] = 0.0; // Default to 0 to prevent errors
                        }
                    }

                    /** @var Product $modelProduct Find the actual product model */
                    $modelProduct = Product::find($product['product_id']);

                    // Calculate total price for all items
                    // Use product['price'] if set, otherwise use modelProduct's selling_price
                    $total_price += (float) ($product['price'] ?? ((float) $modelProduct->selling_price * (int) $product['qty']));
                    // Sum up individual item discounts
                    $total_discount_per_item += (float) ($product['discount_price'] ?? 0);
                    // This calculation is performed but the variable is not used in the final $request array
                    $total_price_after_discount = $total_price - $total_discount_per_item;
                    // Calculate total cost based on initial price
                    $total_cost += (float) $modelProduct->initial_price * (int) $product['qty'];
                }
            );

            // Ensure tax is a float before calculation
            $tax = (float) ($data['tax'] ?? 0);
            $tax_price = $total_price * $tax / 100;
            $total_price = $tax_price + $total_price;

            // Calculate total quantity
            $total_qty = collect($data['products'])->sum('qty');
            // Ensure discount_price is a float
            $discount_price = (float) ($data['discount_price'] ?? 0);

            // Apply voucher if provided
            if ($data['voucher'] ?? false) {
                $voucherService = new VoucherService();
                if ($voucher = $voucherService->applyable($data['voucher'], $total_price)) {
                    $discount_price = (float) $voucher->calculate();
                    // $total_price = $total_price - $discount_price; // This line is commented out in original, keep as is
                    $voucher->reduceUsed();
                }
            }

            // Prepare the request array with calculated values
            $request = [
                'discount_price' => $discount_price,
                'total_price' => $total_price,
                'total_cost' => $total_cost,
                'total_qty' => $total_qty,
                // Calculate money changes based on total price, discounts, and paid money
                'money_changes' => $payed_money - ($total_price - $discount_price - $total_discount_per_item),
                'total_discount_per_item' => $total_discount_per_item,
                'tax_price' => $tax_price,
                'tax' => $tax,
                'payed_money' => $payed_money,
            ];
        } else {
            // If 'friend_price' is true or not set, use provided total_price
            $request = [
                'money_changes' => $payed_money - (float) ($data['total_price'] ?? 0),
                'payed_money' => $payed_money,
            ];
        }

        // Set payment method to 'Cash' if not specified
        if (!isset($data['payment_method_id'])) {
            $cashPaymentMethod = PaymentMethod::where('name', 'Cash')->first();
            if ($cashPaymentMethod) {
                $request = array_merge($request, [
                    'payment_method_id' => $cashPaymentMethod->id,
                ]);
            } else {
                // Handle case where 'Cash' payment method is not found
                // You might want to log this or throw an exception
            }
        } else {
            /** @var PaymentMethod $pMethod Find the payment method model */
            $pMethod = PaymentMethod::find($data['payment_method_id']);
            // If payment method is credit, money changes should be 0
            if ($pMethod && $pMethod->is_credit) {
                $request['money_changes'] = 0.0;
            }
        }

        return $request;
    }
}
