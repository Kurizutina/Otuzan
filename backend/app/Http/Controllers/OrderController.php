<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Queue;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\ClientOrder;
use App\Support\DeliveryFee;
use App\Support\OrderSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    // Same ceiling as a single Pay Bills amount; also keeps TotalPrice well
    // inside the decimal(10,2) column.
    private const MAX_ITEMS_TOTAL = 999999.99;

    // Columns embedded in order responses (see index()).
    private const CUSTOMER_FIELDS = 'UserID,UserName,Contact,Address,Email';
    private const CUSTOMER_FIELDS_FOR_RIDER = 'UserID,UserName,Contact,Address';
    private const RIDER_FIELDS = 'UserID,UserName';
    private const FINISHED_STATUSES = ['delivered', 'cancelled'];

    // Forward steps after a rider accepts; one step at a time, never backwards.
    private const NEXT_STEP = ['confirmed' => 'preparing', 'preparing' => 'out_for_delivery', 'out_for_delivery' => 'delivered'];

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->Role === 'customer', 403);
        $data = $request->validate([
            'items' => ['nullable', 'array', 'min:1', 'max:50', 'required_without:customItems'],
            'items.*.ProductID' => ['required_with:items', 'integer', 'distinct', 'exists:Product,ProductID'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:99'],
            'customItems' => ['nullable', 'array', 'min:1', 'max:50', 'required_without:items'],
            'customItems.*.name' => ['required_with:customItems', 'string', 'max:255'],
            'customItems.*.quantity' => ['required_with:customItems', 'integer', 'min:1', 'max:99'],
            'customItems.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'source' => ['nullable', 'string', 'max:150'],
            'label' => ['nullable', 'string', 'max:255'],
            'section' => ['nullable', Rule::in(['food', 'item'])],
            // Stored in the JSON snapshot, not trusted for any business
            // decision. It lets the customer browser reconcile its local
            // card with this authoritative record even if the asynchronous
            // OrderID patch is interrupted.
            'clientOrderId' => ['nullable', 'string', 'max:100'],
            'deliveryAddress' => [
                'bail',
                'required',
                'string',
                'max:2000',
                function ($attribute, $value, $fail) {
                    if (!DeliveryFee::zoneFor($value)) {
                        $fail('Delivery address must be within a supported delivery location (CLSU Main Campus, Bagong Sikat, Villa Javier, Bukang Liwayway, Tobias Ville, Villa Isidra, PNR Main).');
                    }
                },
            ],
            // Still accepted from older clients, but ignored: the fee is
            // computed below from the customer's type and the Manila time.
            'serviceFee' => ['nullable', 'numeric', 'min:0', 'max:500'],
        ]);

        // Idempotency guard: if the same clientOrderId arrives more than once
        // (double-tap, retry, duplicate tab), return the already-created order
        // instead of inserting a second one. Scoped to the authenticated user so
        // one customer cannot fish for another's orders by guessing an ID.
        return ClientOrder::once($request, $data['clientOrderId'] ?? null, function () use ($data, $request) {
            $existing = ClientOrder::recent($request, $data['clientOrderId'] ?? null);
            if ($existing) {
                return response()->json(['order' => $existing->load('items.product')], 200);
            }
            return $this->createOrder($data, $request);
        });
    }

    private function createOrder(array $data, Request $request): JsonResponse
    {
        $order = DB::transaction(function () use ($data, $request) {

            $serviceFee = DeliveryFee::for($request->user()->UserType);
            if (!empty($data['customItems'])) {
                $itemsTotal = collect($data['customItems'])->sum(fn ($item) => (float) ($item['price'] ?? 0) * $item['quantity']);
                abort_if($itemsTotal > self::MAX_ITEMS_TOTAL, 422, 'The order total is too large.');
                return Order::create([
                    'UserID' => $request->user()->UserID,
                    'TotalPrice' => $itemsTotal + $serviceFee,
                    'ServiceFee' => $serviceFee,
                    'OrderDate' => now(),
                    'DeliveryAddress' => $data['deliveryAddress'],
                    'OrderSnapshot' => [
                        'source' => $data['source'] ?? 'Otu-Zan',
                        'label' => $data['label'] ?? 'Customer order',
                        'section' => $data['section'] ?? 'food',
                        'clientOrderId' => $data['clientOrderId'] ?? null,
                        'items' => $data['customItems'],
                    ],
                    'DeliveryStatus' => 'pending_rider',
                ]);
            }
            $productIds = collect($data['items'])->pluck('ProductID');
            $products = Product::whereIn('ProductID', $productIds)->where('IsActive', true)->get()->keyBy('ProductID');

            $itemsTotal = 0;
            $lineItems = [];
            foreach ($data['items'] as $item) {
                $product = $products->get($item['ProductID']);
                abort_unless($product, 422, 'One or more items are no longer available.');
                $itemsTotal += $product->ProductPrice * $item['quantity'];
                $lineItems[] = [
                    'ProductID' => $product->ProductID,
                    'OrderItemPrice' => $product->ProductPrice,
                    'ProductQuantity' => $item['quantity'],
                ];
            }
            abort_if($itemsTotal > self::MAX_ITEMS_TOTAL, 422, 'The order total is too large.');

            $order = Order::create([
                'UserID' => $request->user()->UserID,
                'TotalPrice' => $itemsTotal + $serviceFee,
                'ServiceFee' => $serviceFee,
                'OrderDate' => now(),
                'DeliveryAddress' => $data['deliveryAddress'],
                'OrderSnapshot' => ['clientOrderId' => $data['clientOrderId'] ?? null],
                'DeliveryStatus' => 'pending_rider',
            ]);
            foreach ($lineItems as $lineItem) {
                $order->items()->create($lineItem);
            }

            return $order;
        });

        return response()->json(['order' => $order->load('items.product')], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // 'user'/'rider'/brand chain: not just for display - this is the data
        // a rider synthesizes a full order card from when this order was
        // placed on a customer's own device and never touched this rider's
        // local storage (see useBackendOrders.js's toLocalOrderShape).
        // Only the fields the screens read (useBackendOrders' toLocalOrderShape):
        // a rider sees who and where to deliver to, never the customer's
        // email or account flags; a customer sees only the rider's name.
        $customerFields = $user->Role === 'driver' ? self::CUSTOMER_FIELDS_FOR_RIDER : self::CUSTOMER_FIELDS;
        $query = Order::query()->with(['items.product.brand.service', 'payments', 'user:'.$customerFields, 'rider:'.self::RIDER_FIELDS])->orderByDesc('OrderDate');

        if ($user->Role === 'driver') {
            $query->where('AssignedRiderID', $user->UserID);
        } else {
            $query->where('UserID', $user->UserID);
        }

        $paginated = $query->paginate($this->applyScope($request, $query));
        $this->attachQueuePositions($paginated);
        $this->attachDeliveryEstimates($paginated);
        return response()->json($paginated);
    }

    public function indexAll(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'string', 'max:200'],
            'service' => ['nullable', Rule::in(['food', 'item', 'bills'])], 'search' => ['nullable', 'string', 'max:100'],
            'payment' => ['nullable', Rule::in(['pending'])],
        ]);
        $query = Order::query()->with(['items.product.brand.service', 'user:'.self::CUSTOMER_FIELDS, 'rider:'.self::RIDER_FIELDS, 'payments'])->orderByDesc('OrderDate');
        // Comma-separated so the History tab can ask for delivered+cancelled
        // in one request instead of two - a single status still works
        // exactly as before.
        $status = $request->query('status');
        if ($status) $query->whereIn('DeliveryStatus', explode(',', $status));
        // Filtered here rather than on the loaded page, so a page is never
        // half empty because most of its orders belong to another service.
        $service = $request->query('service');
        if ($service) $query->whereRaw(OrderSql::service().' = ?', [$service]);
        if ($request->query('payment') === 'pending') {
            $query->where('DeliveryStatus', '!=', 'cancelled')->whereHas('payments', fn ($payment) => $payment->where('PaymentStatus', 'pending'));
        }

        // Exact-date filter (Y-m-d, Asia/Manila business calendar day - same
        // convention as revenue()).
        $date = $request->query('date');
        if ($date) $this->whereManilaDate($query, $date);

        // History search: an order number ("123", "#123", "Order #123"), or
        // part of the customer's name, email or contact number. A short
        // number is an order number only - otherwise "12" would also match
        // every phone number containing 12.
        $search = trim((string) $request->query('search'));
        if (preg_match('/^(?:order\s*)?#\s*(\d+)$|^(\d{1,6})$/i', $search, $match)) {
            $query->where('OrderID', (int) ($match[1] ?: $match[2]));
        } elseif ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('user', fn ($user) => $user->where(fn ($fields) => $fields->where('UserName', 'like', $like)
                ->orWhere('Email', 'like', $like)->orWhere('Contact', 'like', $like)));
        }

        $paginated = $query->paginate($this->applyScope($request, $query));
        $this->attachQueuePositions($paginated);
        $this->attachDeliveryEstimates($paginated);
        return response()->json($paginated);
    }

    // One Asia/Manila calendar day. OrderDate is stored UTC, so the day's
    // boundaries are converted to UTC rather than comparing the stored value
    // against a naive date string (and the OrderDate index still applies).
    private function whereManilaDate($query, string $date): void
    {
        $dayStart = Carbon::createFromFormat('Y-m-d', $date, 'Asia/Manila')->startOfDay()->setTimezone('UTC');
        $query->where('OrderDate', '>=', $dayStart)->where('OrderDate', '<', $dayStart->copy()->addDay());
    }

    // ?scope=active is what the live screens poll every 2 seconds: every
    // order still in progress however old, plus finished ones from the last
    // 24 hours (the customer's "today" list, the rider's 12-hour history, the
    // 24-hour report window), delivered orders with an open report, and bill
    // orders still waiting for payment review. A plain newest-50 page let an
    // old open order fall off the screen once 50 newer orders existed.
    // Open orders come first, so only a backlog of over 200 open orders could
    // push one off the page. Returns the page size for the scope.
    private function applyScope(Request $request, $query): int
    {
        if ($request->query('scope') !== 'active') {
            return min(max((int) $request->query('per_page', 10), 1), 50);
        }

        $since = now()->subDay();
        $query->where(function ($active) use ($since) {
            $active->whereNotIn('DeliveryStatus', self::FINISHED_STATUSES)
                ->orWhere('StatusUpdatedAt', '>=', $since)
                ->orWhere(fn ($old) => $old->whereNull('StatusUpdatedAt')->where('OrderDate', '>=', $since))
                ->orWhereNotNull('ReportedAt')
                ->orWhere(fn ($bill) => $bill->where('DeliveryStatus', '!=', 'cancelled')
                    ->whereHas('payments', fn ($payment) => $payment->where('PaymentStatus', 'pending')));
        })->reorder()
            ->orderByRaw("CASE WHEN DeliveryStatus IN ('delivered', 'cancelled') THEN 1 ELSE 0 END")
            ->orderByDesc('OrderDate');

        return min(max((int) $request->query('per_page', 200), 1), 200);
    }

    // Delivery-time estimate, computed here so every device shows the same
    // value. Measured from order placement to delivery, so it already covers
    // the usual wait in the queue; ordersAhead is shown alongside it, not
    // added on top. Basis: the median of the last 20 delivered orders
    // (ignoring durations under 5 or over 180 minutes as bad data); with
    // fewer than 5 usable orders it falls back to the original flat formula
    // (40 min + 2 per extra item, capped at 50). A range of +/-5 minutes
    // around that value is returned because it is an estimate, not a promise.
    private function attachDeliveryEstimates($paginated): void
    {
        $durations = Order::where('DeliveryStatus', 'delivered')->whereNotNull('StatusUpdatedAt')
            ->orderByDesc('StatusUpdatedAt')->limit(20)->get(['OrderDate', 'StatusUpdatedAt'])
            ->map(fn (Order $order) => Carbon::parse($order->OrderDate, 'UTC')->diffInMinutes(Carbon::parse($order->StatusUpdatedAt, 'UTC'), false))
            ->filter(fn ($minutes) => $minutes >= 5 && $minutes <= 180);
        $median = $durations->count() >= 5 ? $durations->median() : null;

        $paginated->getCollection()->transform(function (Order $order) use ($median) {
            $minutes = (int) round($median ?? min(50, 40 + 2 * ($this->itemCount($order) - 1)));
            $order->estimatedMinutes = [
                'min' => max(5, $minutes - 5),
                'max' => $minutes + 5,
                'basis' => $median === null ? 'formula' : 'recent_orders',
                'ordersAhead' => $order->queuePosition ? $order->queuePosition - 1 : null,
            ];
            // The median already covers the wait for a rider, so it counts
            // from placement. The flat formula doesn't, so once a rider has
            // accepted it counts from then - otherwise a late accept shows
            // "Due now" right away. Naive UTC, like every other datetime the
            // API returns.
            $start = $median === null && $order->ConfirmedAt ? $order->ConfirmedAt : $order->OrderDate;
            $order->estimatedCompletionAt = Carbon::parse($start, 'UTC')->addMinutes($minutes + 5)->format('Y-m-d H:i:s');
            return $order;
        });
    }

    private function itemCount(Order $order): int
    {
        $quantities = $order->items->isNotEmpty()
            ? $order->items->pluck('ProductQuantity')
            : collect($order->OrderSnapshot['items'] ?? [])->pluck('quantity');
        return max(1, (int) $quantities->sum());
    }

    // Computes revenue directly from every non-cancelled order in the table,
    // not from whatever page the admin dashboard happens to have loaded -
    // indexAll()/the frontend used to derive "total revenue" by summing the
    // capped, paginated order list it already had for display, so once order
    // volume passed the 50-per-page cap, older orders silently fell out of
    // the total. Revenue is the ServiceFee column (the delivery/service
    // charge Otu-Zan actually earns), matching what the dashboard already
    // showed - never the item subtotal or a bill's pass-through amount.
    // Calendar-day bucketing uses Asia/Manila (UTC+8) since OrderDate is
    // stored in UTC (config('app.timezone')) - a raw UTC-midnight boundary
    // would flip an order into "yesterday" mid-afternoon local time.
    // Optional ?date=Y-m-d (Asia/Manila) narrows total/byService/orderCount to
    // that single calendar day - `daily` always covers every day on record
    // regardless of the filter, since that's the series the Revenue tab's
    // line graph plots and narrowing it would defeat the point of a trend
    // chart.
    public function revenue(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $requestedDate = $request->query('date');

        // Summed in the database per Manila day and hour (OrderSql), so the
        // tab costs the same whether there are a hundred orders or a hundred
        // thousand - it used to load every order with its items into memory.
        $day = OrderSql::manilaDay();
        $hour = OrderSql::manilaHour();
        $buckets = Order::where('DeliveryStatus', '!=', 'cancelled')
            ->selectRaw("{$day} AS manila_day, {$hour} AS manila_hour, COUNT(*) AS order_count, SUM(ServiceFee) AS fees")
            ->groupByRaw("{$day}, {$hour}")
            ->toBase()->get();

        // Classified per order in a derived table, then grouped by the plain
        // column - avoids grouping by an expression that holds subqueries.
        $perOrder = Order::where('DeliveryStatus', '!=', 'cancelled')
            ->selectRaw(OrderSql::service().' AS order_service, ServiceFee');
        if ($requestedDate) $this->whereManilaDate($perOrder, $requestedDate);
        $feesByService = DB::query()->fromSub($perOrder->toBase(), 'per_order')
            ->selectRaw('order_service, SUM(ServiceFee) AS fees')
            ->groupBy('order_service')->pluck('fees', 'order_service');

        $daily = [];
        $byService = ['food' => 0.0, 'item' => 0.0, 'bills' => 0.0];
        foreach ($feesByService as $name => $fees) {
            $byService[$name] = (float) $fees;
        }
        $total = 0.0;
        $orderCount = 0;
        // Peak ordering time (Layer 1 analytics) - always all-time, same as
        // $daily above, since a single filtered day can't show a meaningful
        // day-of-week pattern and hour-of-day is more useful aggregated
        // across every day on record than for one day in isolation.
        // hourCounts index = hour of day (0-23); dayCounts index = Carbon's
        // dayOfWeek (0 = Sunday .. 6 = Saturday), both in Asia/Manila.
        $hourCounts = array_fill(0, 24, 0);
        $dayCounts = array_fill(0, 7, 0);

        foreach ($buckets as $bucket) {
            $date = (string) $bucket->manila_day;
            $count = (int) $bucket->order_count;
            $fees = (float) $bucket->fees;
            $daily[$date] = ($daily[$date] ?? 0) + $fees;
            $hourCounts[(int) $bucket->manila_hour] += $count;
            $dayCounts[Carbon::parse($date)->dayOfWeek] += $count;

            if ($requestedDate && $date !== $requestedDate) continue;

            $total += $fees;
            $orderCount += $count;
        }

        ksort($daily);
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $peakHour = array_search(max($hourCounts), $hourCounts);
        $peakDay = array_search(max($dayCounts), $dayCounts);

        return response()->json([
            'total' => round($total, 2),
            'byService' => array_map(fn ($value) => round($value, 2), $byService),
            'daily' => collect($daily)->map(fn ($value, $date) => ['date' => $date, 'revenue' => round($value, 2)])->values(),
            'orderCount' => $orderCount,
            'peakHours' => collect($hourCounts)->map(fn ($count, $hour) => ['hour' => $hour, 'orders' => $count])->values(),
            'peakDays' => collect($dayCounts)->map(fn ($count, $day) => ['day' => $dayNames[$day], 'orders' => $count])->values(),
            'peakHour' => array_sum($hourCounts) > 0 ? $peakHour : null,
            'peakDay' => array_sum($dayCounts) > 0 ? $dayNames[$peakDay] : null,
        ]);
    }

    // Data Analytics Layer 2 (diagnostic) - RFM customer segmentation.
    // Recency/Frequency/Monetary, scored 1-5 by quintile across the
    // customer base (standard RFM technique - see TODO.md's citations),
    // then mapped to named segments so the paper has a real, citable
    // methodology instead of "we counted things."
    //
    // Monetary here is each customer's own total spend (TotalPrice - items
    // plus service fee), deliberately NOT the same figure as the business's
    // own Revenue above (ServiceFee only) - RFM's Monetary is about
    // identifying valuable customers, which a flat delivery fee can't do
    // (every order carries the same fee regardless of size); the business's
    // still-open Revenue definition (High Priority) is a different question
    // and doesn't affect this at all. Only non-cancelled orders count,
    // matching Revenue's own exclusion.
    public function customerSegments(): JsonResponse
    {
        $now = Carbon::now();

        // whereHas (WHERE EXISTS) rather than a HAVING clause on the
        // withCount alias - portable across MySQL and SQLite (tests), and it
        // skips customers who never ordered before loading them at all.
        $counted = fn ($query) => $query->where('DeliveryStatus', '!=', 'cancelled');
        $customers = User::select(['UserID', 'UserName', 'Email'])->where('Role', 'customer')
            ->whereHas('orders', $counted)
            ->withCount(['orders as frequency' => $counted])
            ->withSum(['orders as monetary' => $counted], 'TotalPrice')
            ->withMax(['orders as last_order_date' => $counted], 'OrderDate')
            ->get();

        if ($customers->isEmpty()) {
            return response()->json(['customers' => [], 'segments' => [], 'totalCustomers' => 0]);
        }

        $customers = $customers->map(function ($customer) use ($now) {
            $customer->recencyDays = (int) round(Carbon::parse($customer->last_order_date, 'UTC')->diffInDays($now));
            return $customer;
        });

        // Quintile score, 1 (worst) - 5 (best), computed by rank rather than
        // a fixed threshold so it adapts to however many customers actually
        // exist - a handful of test accounts and a thousand real ones both
        // still produce a meaningful 1-5 spread instead of everyone landing
        // in the same bucket.
        // Rank = position of the value's first occurrence in the sorted list,
        // looked up from a map (searching the list per customer was O(n^2)).
        $score = function ($collection, string $field, bool $lowerIsBetter = false) {
            $sorted = $collection->pluck($field)->map(fn ($value) => (float) $value)->sort()->values();
            $count = $sorted->count();
            $firstRank = [];
            foreach ($sorted as $index => $value) {
                $firstRank[(string) $value] ??= $index;
            }
            return function ($value) use ($firstRank, $count, $lowerIsBetter) {
                $rank = $firstRank[(string) (float) $value];
                $percentile = $count > 1 ? $rank / ($count - 1) : 1;
                $quintile = min(5, (int) floor($percentile * 5) + 1);
                return $lowerIsBetter ? 6 - $quintile : $quintile;
            };
        };
        $recencyScorer = $score($customers, 'recencyDays', lowerIsBetter: true);
        $frequencyScorer = $score($customers, 'frequency');
        $monetaryScorer = $score($customers, 'monetary');

        // Simplified segment mapping (5 named buckets, not the full 25-cell
        // RFM matrix) - matches the level of detail CleverTap/mainstream CRM
        // tools present by default (see TODO.md's citation), appropriate for
        // a dashboard rather than a dedicated analytics tool.
        $segmentFor = function (int $r, int $f) {
            if ($r >= 4 && $f >= 4) return 'Champions';
            if ($r >= 3 && $f >= 4) return 'Loyal';
            if ($r <= 2 && $f >= 3) return 'At Risk';
            if ($r >= 4 && $f <= 2) return 'New';
            if ($r <= 2 && $f <= 2) return 'Lost';
            return 'Needs Attention';
        };

        $results = $customers->map(function ($customer) use ($recencyScorer, $frequencyScorer, $monetaryScorer, $segmentFor) {
            $r = $recencyScorer($customer->recencyDays);
            $f = $frequencyScorer($customer->frequency);
            $m = $monetaryScorer((float) $customer->monetary);
            return [
                'id' => $customer->UserID,
                'name' => $customer->UserName,
                'email' => $customer->Email,
                'recencyDays' => $customer->recencyDays,
                'frequency' => $customer->frequency,
                'monetary' => round((float) $customer->monetary, 2),
                'rfmScore' => "{$r}{$f}{$m}",
                'segment' => $segmentFor($r, $f),
            ];
        })->sortBy('recencyDays')->values();

        $segmentCounts = $results->countBy('segment');
        $segmentOrder = ['Champions', 'Loyal', 'At Risk', 'New', 'Needs Attention', 'Lost'];

        return response()->json([
            'customers' => $results,
            'segments' => collect($segmentOrder)
                ->map(fn ($name) => ['segment' => $name, 'count' => $segmentCounts->get($name, 0)])
                ->filter(fn ($row) => $row['count'] > 0)
                ->values(),
            'totalCustomers' => $results->count(),
        ]);
    }

    // Data Analytics Layer 3 (predictive) - plain 7-day moving average,
    // deliberately not a real ML forecast (no seasonality, no tuning) - a
    // capstone timeline doesn't support more, and TODO.md scopes this layer
    // as "lowest priority, simplest viable" on purpose. Forecast for
    // tomorrow is just the average daily order count over the last 7 days
    // with data; if fewer than 7 days exist yet, it averages whatever's
    // there instead of refusing to answer.
    public function demandForecast(): JsonResponse
    {
        $timezone = 'Asia/Manila';
        $windowSize = 7;
        $today = Carbon::now($timezone)->startOfDay();

        $day = OrderSql::manilaDay();
        $dailyCounts = Order::where('DeliveryStatus', '!=', 'cancelled')
            ->selectRaw("{$day} AS manila_day, COUNT(*) AS order_count")
            ->groupByRaw($day)->toBase()->pluck('order_count', 'manila_day')
            ->map(fn ($count) => (int) $count)->all();
        ksort($dailyCounts);

        // Zero-filled calendar days, not just days that happened to have an
        // order - averaging only non-zero days would inflate the forecast
        // whenever there's a gap (e.g. 2 order-days out of the last 7 read
        // as "busy days average 2/day", when the true rate is 2/7). Capped
        // to how many days have actually elapsed since the earliest record,
        // so a brand-new dataset with 2 days of history averages over 2
        // days, not padded with 5 phantom empty days that never happened.
        $earliestDate = empty($dailyCounts) ? $today : Carbon::parse(array_key_first($dailyCounts), $timezone);
        $daysOfHistory = max(1, $earliestDate->diffInDays($today) + 1);
        $effectiveWindow = min($windowSize, $daysOfHistory);

        $windowTotal = 0;
        for ($i = 1; $i <= $effectiveWindow; $i++) {
            $windowTotal += $dailyCounts[$today->copy()->subDays($i)->toDateString()] ?? 0;
        }
        $forecast = round($windowTotal / $effectiveWindow, 1);

        return response()->json([
            'daily' => collect($dailyCounts)->map(fn ($count, $date) => ['date' => $date, 'orders' => $count])->values(),
            'forecastNextDay' => $forecast,
            'windowSize' => $effectiveWindow,
        ]);
    }

    // Position is computed fresh on every request from the actual set of
    // orders still waiting - never stored/decremented - so it can't drift
    // out of sync the way a mutated counter could under concurrent orders.
    // Only orders that still have a 'waiting' Queue row (i.e. haven't been
    // confirmed, declined, or otherwise moved yet) get a position; everyone
    // else gets null since they're no longer "in line."
    private function attachQueuePositions($paginated): void
    {
        $waitingOrderIds = Queue::where('QueueStatus', 'waiting')->orderBy('QueueDate')->pluck('OrderID');
        $positionByOrderId = [];
        foreach ($waitingOrderIds as $index => $orderId) {
            $positionByOrderId[$orderId] = $index + 1;
        }
        $paginated->getCollection()->transform(function (Order $order) use ($positionByOrderId) {
            $order->queuePosition = $positionByOrderId[$order->OrderID] ?? null;
            return $order;
        });
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled'])],
        ]);

        $isAdmin = $user->Role === 'admin';
        $isAssignedRider = $user->Role === 'driver' && $order->AssignedRiderID === $user->UserID;
        // A customer may cancel their own order themselves - but only their
        // own order, only to 'cancelled', and only while it's still
        // pending_rider. Once admin or a rider has actually started acting
        // on it (confirmed onward), it's out of the customer's hands; they'd
        // need to contact the business directly at that point.
        $isOwningCustomerCancelling = $user->Role === 'customer'
            && $order->UserID === $user->UserID
            && $data['status'] === 'cancelled'
            && $order->DeliveryStatus === 'pending_rider'
            && is_null($order->AssignedRiderID);
        abort_unless($isAdmin || $isAssignedRider || $isOwningCustomerCancelling, 403);
        abort_if(in_array($order->DeliveryStatus, ['delivered', 'cancelled'], true), 422, 'This order is already finalized.');

        // Allowed transitions (10/12 audit): only the assigned rider accepts
        // or declines a pending order; after that, rider or admin move it
        // forward exactly one step; the admin may cancel any open order.
        // A rider can no longer cancel an order they already accepted.
        $current = $order->DeliveryStatus;
        $isOneStepForward = (self::NEXT_STEP[$current] ?? null) === $data['status'];
        $allowed = $isOwningCustomerCancelling
            || ($isAssignedRider && ($current === 'pending_rider' ? in_array($data['status'], ['confirmed', 'cancelled'], true) : $isOneStepForward))
            || ($isAdmin && ($data['status'] === 'cancelled' || $isOneStepForward));
        abort_unless($allowed, 422, 'That status change is not allowed for this order.');

        // A rider declining an order they were assigned but have not accepted
        // is not the end of the customer's order: it goes back to the admin's
        // unassigned pool so another rider can take it.
        if ($isAssignedRider && $data['status'] === 'cancelled' && $order->DeliveryStatus === 'pending_rider') {
            $order->update(['DeliveryStatus' => 'pending_rider', 'AssignedRiderID' => null, 'StatusUpdatedAt' => now()]);
            $this->notifyCustomer($order, 'Rider cancelled your order', "Order #{$order->OrderID} was cancelled by the rider. We're reassigning it to another rider and will notify you once one accepts.", 'status');
            return response()->json(['order' => $order->fresh(['items.product', 'rider:'.self::RIDER_FIELDS])]);
        }

        $hadRider = !is_null($order->AssignedRiderID);
        if ($isAdmin) AuditLog::record('order status set by admin: '.$data['status'], $request, ['order' => $order->OrderID, 'from' => $order->DeliveryStatus]);
        $order->update(['DeliveryStatus' => $data['status'], 'StatusUpdatedAt' => now()]
            + ($data['status'] === 'confirmed' ? ['ConfirmedAt' => now()] : []));
        // Any explicit status transition means the order left the raw
        // pre-confirmation line (confirmed, or declined via 'cancelled') -
        // it's either now being actively tracked through its own progress
        // steps, or done entirely. Guarded by QueueStatus='waiting' so a
        // later transition on the same order (confirmed -> preparing) is a
        // harmless no-op instead of re-touching an already-closed entry.
        $order->queueEntries()->where('QueueStatus', 'waiting')->update(['QueueStatus' => 'done']);
        $this->notifyStatusChange($order, $data['status'], $isAdmin ? ($hadRider ? 'admin' : 'admin_no_rider') : null);
        return response()->json(['order' => $order->fresh(['items.product', 'rider:'.self::RIDER_FIELDS])]);
    }

    private function notifyCustomer(Order $order, string $title, string $message, string $type): void
    {
        Notification::create([
            'UserID' => $order->UserID,
            'NotificationMessage' => json_encode([
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'orderId' => $order->OrderID,
            ]),
            'NotificationSeen' => false,
            'NotificationDate' => now(),
        ]);
    }

    // Admin/rider actions were the actual gap step 1g exists to close: an
    // order placed on a customer's own phone gets accepted/declined from an
    // admin's console on a completely different device, and the customer
    // needs to find out regardless of which device they check from next -
    // a notification written only to the acting admin/rider's own browser
    // (the old localStorage-only behavior) never reached them at all.
    private function notifyStatusChange(Order $order, string $status, ?string $cancelledBy = null): void
    {
        $content = [
            'confirmed' => ['Order accepted', 'was accepted. Tracking is now available.'],
            'cancelled' => ['Order cancelled', match ($cancelledBy) {
                'admin_no_rider' => 'was cancelled by the admin because no riders are currently available.',
                'admin' => 'was cancelled by the admin.',
                default => 'was cancelled.',
            }],
            'preparing' => ['Order is being prepared', 'is now being prepared.'],
            'out_for_delivery' => ['Order is out for delivery', 'is on the way.'],
            'delivered' => ['Order delivered', 'has been delivered.'],
        ][$status] ?? null;
        if (!$content) return;

        // A customer never saw who was actually delivering their order -
        // no customer-facing component read AssignedRiderID at all. Rather
        // than build a separate "your rider" UI, the rider's name rides
        // along in the same notification a customer already checks: once
        // one is assigned and explicitly accepts the order, the acceptance
        // and delivery updates name them by first name - the message stays
        // honest (no name) for the states before a rider is assigned.
        $riderName = $order->rider?->UserName;
        // The contact number rides along for the same reason the name does:
        // the customer needs a way to reach the rider handling the delivery.
        // Standard counterparty-contact practice (delivery apps do this),
        // and the app already shares the customer's contact with the rider.
        $riderContact = trim((string) ($order->rider?->Contact ?? ''));
        $riderSuffix = $riderName && in_array($status, ['confirmed', 'out_for_delivery', 'delivered'], true)
            ? " {$this->firstName($riderName)} is your rider."
              . ($riderContact !== '' ? " Contact: {$riderContact}." : '')
            : '';

        $this->notifyCustomer(
            $order,
            $content[0],
            "Order #{$order->OrderID} {$content[1]}{$riderSuffix}",
            $status === 'cancelled' ? 'cancelled' : 'status'
        );
    }

    private function firstName(string $fullName): string
    {
        return trim(explode(' ', trim($fullName))[0]) ?: $fullName;
    }

    public function assign(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'riderId' => ['nullable', 'integer', 'exists:Users,UserID'],
        ]);

        // Once a rider has accepted (or the order is finished) the assignment
        // is locked; swapping or removing the rider mid-delivery orphaned it.
        abort_unless($order->DeliveryStatus === 'pending_rider', 422, 'A rider can only be assigned or removed while the order is waiting for one.');

        $riderId = $data['riderId'] ?? null;
        if ($riderId) {
            $rider = User::find($riderId);
            abort_unless($rider && $rider->Role === 'driver', 422, 'That user is not a rider.');
            // Deliberate rule change (9/29, requested): a rider may hold
            // several active deliveries at once. The old guard that rejected
            // a second assignment with 422 "already has an active delivery
            // in progress" is gone - don't reintroduce it without asking.
        }

        // Assignment reserves the order for a rider, but is not acceptance.
        // The assigned rider explicitly confirms or cancels it from their
        // dashboard; only that status action advances the queue and informs
        // the customer that the order was accepted.
        $order->update(['AssignedRiderID' => $riderId]);
        AuditLog::record($riderId ? 'order assigned' : 'order unassigned', $request, ['order' => $order->OrderID, 'rider' => $riderId]);

        return response()->json(['order' => $order->fresh(['items.product', 'rider:'.self::RIDER_FIELDS])]);
    }

    // Post-delivery report window (9/29): the owning customer has 24h after
    // delivery to flag a problem. A submitted report reopens the order chat
    // for both sides (MessageController::store keys off ReportedAt) and marks
    // the order on the admin History tab until an admin dismisses it
    // (clearReport). Status stays 'delivered' - a report is a flag on a
    // finished order, not a new delivery state.
    public function report(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->Role === 'customer' && $order->UserID === $user->UserID, 403);
        abort_unless($order->DeliveryStatus === 'delivered', 422, 'Only a delivered order can be reported.');
        abort_if(!is_null($order->ReportedAt), 422, 'This order has already been reported.');
        // StatusUpdatedAt is set on every transition, and no transition can
        // happen after delivery (updateStatus rejects finalized orders), so
        // for a delivered order it is the delivery time. OrderDate is the
        // fallback for rows that predate that column.
        $deliveredAt = Carbon::parse($order->StatusUpdatedAt ?? $order->OrderDate);
        abort_if($deliveredAt->copy()->addHours(24)->isPast(), 422, 'The 24-hour reporting window for this order has closed.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $order->update(['ReportedAt' => now(), 'ReportReason' => $data['reason']]);

        return response()->json(['order' => $order->fresh()]);
    }

    // Admin resolves a report: clears the flag, which also closes the
    // reopened chat again (MessageController::store keys off ReportedAt).
    // Gated by the route-level permission:orders.manage middleware
    // (admin-only) - no redundant controller role check, same as assign().
    public function clearReport(Request $request, Order $order): JsonResponse
    {
        abort_if(is_null($order->ReportedAt), 422, 'This order has no open report.');
        $order->update(['ReportedAt' => null, 'ReportReason' => null]);

        return response()->json(['order' => $order->fresh()]);
    }
}
