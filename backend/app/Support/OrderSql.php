<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// SQL expressions over the Orders table, so reports and filters run in the
// database instead of loading every order into PHP. Written for both the
// production MySQL/MariaDB database and the SQLite database the tests use.
class OrderSql
{
    // Asia/Manila is a fixed UTC+8 (the Philippines has no daylight saving),
    // so shifting the UTC OrderDate by 8 hours gives the business calendar
    // day/hour. Keep in step with manilaDateString() in DeliveryAdminDashboard.jsx.
    private const MANILA_OFFSET_HOURS = 8;

    private static function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    // Y-m-d of the order in Manila.
    public static function manilaDay(): string
    {
        $hours = self::MANILA_OFFSET_HOURS;
        return self::isSqlite()
            ? "date(Orders.OrderDate, '+{$hours} hours')"
            : "DATE(DATE_ADD(Orders.OrderDate, INTERVAL {$hours} HOUR))";
    }

    // Hour of day (0-23) of the order in Manila.
    public static function manilaHour(): string
    {
        $hours = self::MANILA_OFFSET_HOURS;
        return self::isSqlite()
            ? "CAST(strftime('%H', Orders.OrderDate, '+{$hours} hours') AS INTEGER)"
            : "HOUR(DATE_ADD(Orders.OrderDate, INTERVAL {$hours} HOUR))";
    }

    // 'bills' | 'item' | 'food', the same rule as the frontend's
    // toLocalOrderShape: an order with a payment is a bill; otherwise the
    // section saved with a custom order; otherwise the service of the first
    // catalog item's brand.
    public static function service(): string
    {
        $section = self::isSqlite()
            ? "json_extract(Orders.OrderSnapshot, '$.section')"
            : "JSON_UNQUOTE(JSON_EXTRACT(Orders.OrderSnapshot, '$.section'))";

        return "CASE"
            ." WHEN EXISTS (SELECT 1 FROM Payment WHERE Payment.OrderID = Orders.OrderID) THEN 'bills'"
            ." WHEN {$section} IN ('food', 'item') THEN {$section}"
            ." WHEN (SELECT Services.ServiceType FROM OrderItem"
            ." JOIN Product ON Product.ProductID = OrderItem.ProductID"
            ." JOIN Brands ON Brands.BrandID = Product.BrandID"
            ." JOIN Services ON Services.ServiceID = Brands.ServiceID"
            ." WHERE OrderItem.OrderID = Orders.OrderID ORDER BY OrderItem.OrderItemID LIMIT 1) = 'item' THEN 'item'"
            ." ELSE 'food' END";
    }
}
