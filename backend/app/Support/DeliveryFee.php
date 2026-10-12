<?php

namespace App\Support;

use Carbon\Carbon;

// Server-side copy of frontend/src/utils/deliveryRates.js - keep the two in
// sync. The server charges this fee; whatever fee a client sends is ignored.
class DeliveryFee
{
    public const ZONES = ['CLSU Main Campus', 'Bagong Sikat', 'Villa Javier', 'Bukang Liwayway', 'Tobias Ville', 'Villa Isidra', 'PNR Main'];
    public const ZONE_FEE = 75;
    // Surcharge applies strictly AFTER these Asia/Manila times (minutes past midnight).
    private const CUTOFF_MINUTES = ['student' => 20 * 60, 'non_student' => 18 * 60];

    // The app sends the zone name, optionally followed by more detail, so the
    // address must start with a zone; a zone named anywhere else doesn't count.
    public static function zoneFor(?string $address): ?string
    {
        $normalized = mb_strtolower(trim((string) $address));
        foreach (self::ZONES as $zone) {
            if (str_starts_with($normalized, mb_strtolower($zone))) return $zone;
        }
        return null;
    }

    public static function for(?string $userType, ?Carbon $at = null): float
    {
        $manila = ($at ?? Carbon::now())->copy()->setTimezone('Asia/Manila');
        $minutes = $manila->hour * 60 + $manila->minute;
        $cutoff = self::CUTOFF_MINUTES[$userType] ?? self::CUTOFF_MINUTES['non_student'];
        return $minutes > $cutoff ? self::ZONE_FEE * 1.5 : (float) self::ZONE_FEE;
    }
}
