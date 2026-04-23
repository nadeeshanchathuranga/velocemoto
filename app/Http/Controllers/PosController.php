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

        $product = Product::where('barcode', $request->barcode)
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

        if ($product && (float) ($product->discount ?? 0) > 0) {
            if (!is_null($product->discounted_price) && (float) $product->discounted_price > 0) {
                return (float) $product->discounted_price;
            }

            $sellingPrice = (float) ($product->selling_price ?? 0);
            $discountPercent = (float) $product->discount;
            return round($sellingPrice - (($sellingPrice * $discountPercent) / 100), 2);
        }

        if ($saleItems instanceof \Illuminate\Support\Collection && $saleItems->isNotEmpty()) {
            $lineTotal = (float) $saleItems->sum('total_price');
            $lineQty = max(1, (int) $saleItems->sum('quantity'));
            return round($lineTotal / $lineQty, 2);
        }

        return (float) ($saleItem->unit_price ?? optional($product)->selling_price ?? 0);
    }

    public function submit(Request $request)
    {

        if (!Gate::allows('hasRole', ['Admin', 'Cashier'])) {
            abort(403, 'Unauthorized');
        }
        // Combine countryCode and contactNumber to create the phone field


        $customer = null;

        $products = $request->input('products');
        $totalAmount = collect($products)->reduce(function ($carry, $product) {
            return $carry + ($product['quantity'] * $product['selling_price']);
        }, 0);

        $totalCost = collect($products)->reduce(function ($carry, $product) {
            return $carry + ($product['quantity'] * $product['cost_price']);
        }, 0);

        $productDiscounts = collect($products)->reduce(function ($carry, $product) {
            if (isset($product['discount']) && $product['discount'] > 0 && isset($product['apply_discount']) && $product['apply_discount'] != false) {
                $discountAmount = ($product['selling_price'] - $product['discounted_price']) * $product['quantity'];
                return $carry + $discountAmount;
            }
            return $carry;
        }, 0);

        // Get coupon discount if applied
        $couponDiscount = isset($request->input('appliedCoupon')['discount']) ?
            floatval($request->input('appliedCoupon')['discount']) : 0;


        // Calculate total combined discount
        $totalDiscount = $productDiscounts + $couponDiscount ;

        DB::beginTransaction(); // Start a transaction

        try {
            // Save the customer data to the database
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
                        'address' => $request->input('customer.address', ''), // Optional address
                        'member_since' => now()->toDateString(), // Current date
                        'loyalty_points' => 0, // Default value
                    ]);
                }
            }

            // Create the sale record
            $sale = Sale::create([
                'customer_id' => $customer ? $customer->id : null, // Nullable customer_id
                'employee_id' => $request->input('employee_id'),
                'user_id' => $request->input('userId'), // Logged-in user ID
                'order_id' => $request->input('orderid'),
                'total_amount' => $totalAmount, // Total amount of the sale
                'discount' => $totalDiscount, // Default discount to 0 if not provided
                'total_cost' => $totalCost,
                'payment_method' => $request->input('paymentMethod'), // Payment method from the request
                'sale_date' => now()->toDateString(), // Current date
                'cash' => $request->input('cash'),
                'custom_discount' => $request->input('custom_discount'),
                'custom_discount_type' => $request->input('custom_discount_type', 'fixed'),

            ]);

            foreach ($products as $product) {
                // Check stock before saving sale items
                $productModel = Product::find($product['id']);
                if ($productModel) {
                    $hasItemDiscount = isset($product['discount'])
                        && (float) $product['discount'] > 0
                        && !empty($product['apply_discount']);

                    $unitPrice = $hasItemDiscount
                        ? (float) ($product['discounted_price'] ?? $product['selling_price'])
                        : (float) ($product['selling_price'] ?? 0);

                    $newStockQuantity = $productModel->stock_quantity - $product['quantity'];

                    // Prevent stock from going negative
                    if ($newStockQuantity < 0) {
                        // Rollback transaction and return error
                        DB::rollBack();
                        return response()->json([
                            'message' => "Insufficient stock for product: {$productModel->name}
                            ({$productModel->stock_quantity} available)",
                        ], 423);
                    }

                    if ($productModel->expire_date && now()->greaterThan($productModel->expire_date)) {
                        // Rollback transaction and return error
                        DB::rollBack();
                        return response()->json([
                            'message' => "The product '{$productModel->name}' has expired (Expiration Date: {$productModel->expire_date->format('Y-m-d')}).",
                        ], 423); // HTTP 422 Unprocessable Entity
                    }

                    // Create sale item
                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $product['id'],
                        'quantity' => $product['quantity'],
                        'unit_price' => $unitPrice,
                        'total_price' => (float) $product['quantity'] * $unitPrice,
                    ]);

                    StockTransaction::create([
                        'product_id' => $product['id'],
                        'transaction_type' => 'Sold',
                        'quantity' => $product['quantity'],
                        'transaction_date' => now(),
                        'supplier_id' => $productModel->supplier_id ?? null,
                    ]);

                    // Update stock quantity
                    $productModel->update([
                        'stock_quantity' => $newStockQuantity,
                    ]);
                }
            }

            // Commit the transaction
            DB::commit();

            return response()->json(['message' => 'Sale recorded successfully!'], 201);
        } catch (\Exception $e) {
            // Rollback the transaction if any error occurs
            DB::rollBack();

            return response()->json([
                'message' => 'An error occurred while processing the sale.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Customer details saved successfully!',
            'data' => $customer,
        ], 201);
    }
}
