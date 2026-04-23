<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Color;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Size;
use App\Models\StockTransaction;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PosController extends Controller
{
    public function index(Request $request)
    {
        if (!Gate::allows('hasRole', ['Admin', 'Cashier'])) {
            abort(403, 'Unauthorized');
        }

        $allcategories = Category::with('parent')->get()->map(function ($category) {
            $category->hierarchy_string = $category->hierarchy_string; // Access it
            return $category;
        });
        $colors = Color::orderBy('created_at', 'desc')->get();
        $sizes = Size::orderBy('created_at', 'desc')->get();
        $allemployee = Employee::orderBy('created_at', 'desc')->get();


        // Render the page for GET requests
        return Inertia::render('Pos/Index', [
            'product' => null,
            'error' => null,
            'loggedInUser' => Auth::user(),
            'allcategories' => $allcategories,
            'allemployee' => $allemployee,
            'colors' => $colors,
            'sizes' => $sizes,
        ]);
    }

    public function getProduct(Request $request)
    {
        if (!Gate::allows('hasRole', ['Admin', 'Cashier'])) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'barcode' => 'required',
        ]);

        $product = Product::with(['batches' => function($q) {
                $q->where('stock_quantity', '>', 0)->orderBy('created_at', 'asc');
            }])
            ->where('barcode', $request->barcode)
            ->orWhere('code', $request->barcode)
            ->first();

        return response()->json([
            'product' => $product,
            'error' => $product ? null : 'Product not found',
        ]);
    }

    public function getCoupon(Request $request)
    {
        $request->validate(
            ['code' => 'required|string'],
            ['code.required' => 'The coupon code missing.', 'code.string' => 'The coupon code must be a valid string.']
        );

        $coupon = Coupon::where('code', $request->code)->first();

        if (!$coupon) {
            return response()->json(['error' => 'Invalid coupon code.']);
        }

        if (!$coupon->isValid()) {
            return response()->json(['error' => 'Coupon is expired or has been fully used.']);
        }

        return response()->json(['success' => 'Coupon applied successfully.', 'coupon' => $coupon]);
    }

    public function getReturnOrders()
    {
        if (!Gate::allows('hasRole', ['Admin', 'Cashier'])) {
            abort(403, 'Unauthorized');
        }

        $sales = Sale::with('customer')
            ->whereNotNull('order_id')
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get(['id', 'order_id', 'customer_id', 'total_amount', 'discount', 'payment_method', 'sale_date', 'created_at']);

        return response()->json(['sales' => $sales]);
    }

    public function getReturnOrderItems(Sale $sale)
    {
        if (!Gate::allows('hasRole', ['Admin', 'Cashier'])) {
            abort(403, 'Unauthorized');
        }

        $saleItems = SaleItem::with('product')
            ->where('sale_id', $sale->id)
            ->get();

        $grouped = $saleItems
            ->groupBy('product_id')
            ->map(function ($items, $productId) use ($sale) {
                $soldQuantity = (int) $items->sum('quantity');
                $returnedQuantity = (int) ReturnItem::where('sale_id', $sale->id)
                    ->where('product_id', $productId)
                    ->sum('quantity');
                $availableQuantity = max(0, $soldQuantity - $returnedQuantity);

                if ($availableQuantity < 1) {
                    return null;
                }

                $first = $items->first();
                $unitPrice = $this->resolveReturnUnitPrice($first, $items);

                return [
                    'product_id' => (int) $productId,
                    'product' => $first?->product,
                    'sold_quantity' => $soldQuantity,
                    'returned_quantity' => $returnedQuantity,
                    'available_quantity' => $availableQuantity,
                    'unit_price' => $unitPrice,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'sale' => $sale->load('customer'),
            'items' => $grouped,
        ]);
    }

    public function submitReturn(Request $request)
    {
        if (!Gate::allows('hasRole', ['Admin', 'Cashier'])) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'sale_id' => 'required|exists:sales,id',
            'refund_method' => 'required|in:Cash,Card,Online,Exchange',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'nullable|string|max:1000',
        ]);

        $sale = Sale::findOrFail($validated['sale_id']);

        DB::beginTransaction();

        try {
            $refundTotal = 0;

            foreach ($validated['items'] as $returnItemData) {
                $productId = (int) $returnItemData['product_id'];
                $returnQty = (int) $returnItemData['quantity'];
                $reason = $returnItemData['reason'] ?? null;

                $soldQty = (int) SaleItem::where('sale_id', $sale->id)
                    ->where('product_id', $productId)
                    ->sum('quantity');

                $alreadyReturnedQty = (int) ReturnItem::where('sale_id', $sale->id)
                    ->where('product_id', $productId)
                    ->sum('quantity');

                $availableQty = $soldQty - $alreadyReturnedQty;

                if ($soldQty < 1 || $returnQty > $availableQty) {
                    throw ValidationException::withMessages([
                        'items' => ["Return quantity exceeds available quantity for product ID {$productId}."],
                    ]);
                }

                $saleItems = SaleItem::where('sale_id', $sale->id)
                    ->where('product_id', $productId)
                    ->get();

                $unitPrice = $this->resolveReturnUnitPrice($saleItems->first(), $saleItems);

                $refundAmount = $unitPrice * $returnQty;
                $refundTotal += $refundAmount;

                ReturnItem::create([
                    'sale_id' => $sale->id,
                    'customer_id' => $sale->customer_id,
                    'product_id' => $productId,
                    'quantity' => $returnQty,
                    'reason' => $reason ?: 'Customer return',
                    'return_date' => now()->toDateString(),
                ]);

                $product = Product::lockForUpdate()->find($productId);
                if ($product) {
                    $product->stock_quantity = (int) $product->stock_quantity + $returnQty;

                    if (!is_null($product->total_quantity)) {
                        $product->total_quantity = (int) $product->total_quantity + $returnQty;
                    }

                    $product->save();

                    StockTransaction::create([
                        'product_id' => $productId,
                        'transaction_type' => 'Added',
                        'quantity' => $returnQty,
                        'transaction_date' => now(),
                        'supplier_id' => $product->supplier_id ?? null,
                        'reason' => $reason ?: 'Customer return',
                    ]);
                }
            }

            $paymentMethodMap = [
                'Cash' => 'Cash',
                'Card' => 'Credit Card',
                'Online' => 'Online',
                'Exchange' => 'Exchange',
            ];

            if ($validated['refund_method'] !== 'Exchange') {
                Payment::create([
                    'sale_id' => $sale->id,
                    'amount' => -1 * round($refundTotal, 2),
                    'method' => $paymentMethodMap[$validated['refund_method']] ?? 'Cash',
                    'payment_date' => now()->toDateString(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Return processed successfully.',
                'refund_total' => round($refundTotal, 2),
                'sale' => $sale->only(['id', 'order_id', 'sale_date']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($e instanceof ValidationException) {
                throw $e;
            }

            return response()->json([
                'message' => 'An error occurred while processing the return.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveReturnUnitPrice(?SaleItem $saleItem, $saleItems): float
    {
        $product = $saleItem?->product;
        $saleType = $saleItem?->sale_type ?? 'retail';

        // Try product's current discounted price for the original sale type
        if ($product && $product->getDiscountPercent($saleType) > 0) {
            $finalPrice = $product->getFinalPrice($saleType);
            if ($finalPrice > 0) {
                return $finalPrice;
            }
        }

        // Fall back to the actual recorded line-item totals
        if ($saleItems instanceof \Illuminate\Support\Collection && $saleItems->isNotEmpty()) {
            $lineTotal = (float) $saleItems->sum('total_price');
            $lineQty = max(1, (int) $saleItems->sum('quantity'));
            return round($lineTotal / $lineQty, 2);
        }

        return (float) ($saleItem->unit_price ?? optional($product)->getBasePrice($saleType) ?? 0);
    }

    public function submit(Request $request)
    {
        if (!Gate::allows('hasRole', ['Admin', 'Cashier'])) {
            abort(403, 'Unauthorized');
        }

        // Validate and sanitize the global sale type
        $saleType = $request->input('sale_type', 'retail');
        if (!in_array($saleType, ['retail', 'wholesale'])) {
            $saleType = 'retail';
        }

        $customer = null;
        $cartItems = $request->input('products');

        DB::beginTransaction();

        try {
            // --- Save customer (unchanged) ---
            if ($request->input('customer.contactNumber') || $request->input('customer.name') || $request->input('customer.email')) {
                $phone = $request->input('customer.countryCode') . $request->input('customer.contactNumber');
                $customer = Customer::where('email', $request->input('customer.email'))->first();
                $customer1 = Customer::where('phone', $phone)->first();

                if ($customer) {
                    $email = '';
                } else {
                    $email = $request->input('customer.email');
                }

                if ($customer1) {
                    $phone = '';
                }

                if (!empty($phone) || !empty($email) || !empty($request->input('customer.name'))) {
                    $customer = Customer::create([
                        'name' => $request->input('customer.name'),
                        'email' => $email,
                        'phone' => $phone,
                        'address' => $request->input('customer.address', ''),
                        'member_since' => now()->toDateString(),
                        'loyalty_points' => 0,
                    ]);
                }
            }

            // --- SECURE: Recalculate all totals from the database ---
            $totalAmount = 0;
            $totalCost = 0;
            $productDiscounts = 0;
            $saleItemsData = [];

            foreach ($cartItems as $cartItem) {
                $productModel = Product::lockForUpdate()->find($cartItem['id']);
                if (!$productModel) {
                    DB::rollBack();
                    return response()->json(['message' => "Product not found: ID {$cartItem['id']}"], 422);
                }

                $qty = (int) $cartItem['quantity'];
                
                // Determine if a specific batch was selected
                $batchId = $cartItem['selected_batch_id'] ?? null;
                $batchModel = null;
                if ($batchId) {
                    $batchModel = \App\Models\ProductBatch::lockForUpdate()->find($batchId);
                }

                $sourceModel = $batchModel ?: $productModel;

                // Validate stock against the specific batch or main product
                $newStockQuantity = $sourceModel->stock_quantity - $qty;

                // Prevent stock from going negative
                if ($newStockQuantity < 0) {
                    DB::rollBack();
                    $sourceName = $batchModel ? "{$productModel->name} (Batch {$batchModel->batch_no})" : $productModel->name;
                    return response()->json([
                        'message' => "Insufficient stock for: {$sourceName} ({$sourceModel->stock_quantity} available)",
                    ], 423);
                }

                // Prevent selling expired products
                $expireDate = $sourceModel->expire_date;
                if ($expireDate && now()->greaterThan($expireDate)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "The product '{$productModel->name}' has expired (Expiration Date: {$expireDate->format('Y-m-d')}).",
                    ], 423);
                }

                // Get prices from the DB based on sale_type and the source model (Batch or Product)
                $basePrice = $saleType === 'wholesale' 
                    ? ($sourceModel->wholesale_price ?: $sourceModel->retail_price ?: 0)
                    : ($sourceModel->retail_price ?: $sourceModel->selling_price ?: 0);
                    
                $discountPercent = $saleType === 'wholesale'
                    ? ($sourceModel->wholesale_discount ?: 0)
                    : ($sourceModel->retail_discount ?: $sourceModel->discount ?: 0);
                    
                $hasItemDiscount = $discountPercent > 0 && !empty($cartItem['apply_discount']);

                $unitPrice = $basePrice;
                if ($hasItemDiscount) {
                    $discountedPrice = $saleType === 'wholesale'
                        ? $sourceModel->discounted_wholesale_price
                        : ($sourceModel->discounted_retail_price ?: $sourceModel->discounted_price);
                        
                    $unitPrice = $discountedPrice ?: ($basePrice - ($basePrice * ($discountPercent / 100)));
                }

                $lineDiscount = $hasItemDiscount ? ($basePrice - $unitPrice) * $qty : 0;

                $totalAmount += $qty * $basePrice;
                $totalCost += $qty * (float) $sourceModel->cost_price;
                $productDiscounts += $lineDiscount;

                $saleItemsData[] = [
                    'product_id' => $productModel->id,
                    'batch_id' => $batchId,
                    'product_model' => $productModel,
                    'batch_model' => $batchModel,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $qty * $unitPrice,
                    'new_stock' => $newStockQuantity,
                ];
            }

            // Get coupon discount if applied
            $couponDiscount = isset($request->input('appliedCoupon')['discount'])
                ? floatval($request->input('appliedCoupon')['discount'])
                : 0;

            $totalDiscount = $productDiscounts + $couponDiscount;

            // Create the sale record with DB-verified totals
            $sale = Sale::create([
                'customer_id' => $customer ? $customer->id : null,
                'employee_id' => $request->input('employee_id'),
                'user_id' => $request->input('userId'),
                'order_id' => $request->input('orderid'),
                'total_amount' => $totalAmount,
                'discount' => $totalDiscount,
                'total_cost' => $totalCost,
                'payment_method' => $request->input('paymentMethod'),
                'sale_date' => now()->toDateString(),
                'cash' => $request->input('cash'),
                'custom_discount' => $request->input('custom_discount'),
                'custom_discount_type' => $request->input('custom_discount_type', 'fixed'),
            ]);

            // Create sale items and update stock
            foreach ($saleItemsData as $item) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $item['line_total'],
                    'sale_type' => $saleType,
                ]);

                StockTransaction::create([
                    'product_id' => $item['product_id'],
                    'transaction_type' => 'Sold',
                    'quantity' => $item['qty'],
                    'transaction_date' => now(),
                    'supplier_id' => $item['product_model']->supplier_id ?? null,
                ]);

                if ($item['batch_model']) {
                    $item['batch_model']->update([
                        'stock_quantity' => $item['new_stock']
                    ]);
                    $item['product_model']->update([
                        'stock_quantity' => $item['product_model']->stock_quantity - $item['qty']
                    ]);
                } else {
                    $item['product_model']->update([
                        'stock_quantity' => $item['new_stock'],
                    ]);
                }
                
                $item['product_model']->update([
                    'total_quantity' => $item['product_model']->stock_quantity
                ]);
            }

            DB::commit();

            return response()->json(['message' => 'Sale recorded successfully!'], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'An error occurred while processing the sale.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
