<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class Payment extends Model
{
    protected $table = 'Payment';
    protected $primaryKey = 'PaymentID';
    public $timestamps = false;
    protected $fillable = ['OrderID', 'PaymentName', 'PaymentMethod', 'PaymentAmount', 'PaymentStatus', 'PaymentNote'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class, 'OrderID'); }

    // Bill documents are private (audit S10). Anyone who receives this payment
    // in a response already passed the order scoping (owner, assigned rider,
    // admin), so the stored "/uploads/bill-documents/<file>" paths go out as
    // signed links. Done in toArray() because Eloquent's accessor lookup
    // doesn't match StudlyCase columns like PaymentNote when serializing.
    public function toArray(): array
    {
        $array = parent::toArray();
        if (isset($array['PaymentNote'])) $array['PaymentNote'] = self::signDocumentLinks($array['PaymentNote']);
        return $array;
    }

    private static function signDocumentLinks(string $value): string
    {
        $note = json_decode($value, true);
        if (!is_array($note)) return $value;
        // Rounded to the hour so the 2-second order poll keeps returning the
        // same link and the image isn't downloaded again on every poll.
        $expires = Carbon::now()->startOfHour()->addHours(2);
        foreach (['billReceiptUrl', 'transferProofUrl'] as $key) {
            if (is_string($note[$key] ?? null) && preg_match('#^/uploads/bill-documents/([0-9a-f-]{36}\.[a-z]+)$#', $note[$key], $match)) {
                $note[$key] = URL::temporarySignedRoute('bill-documents.show', $expires, ['filename' => $match[1]], absolute: false);
            }
        }
        return json_encode($note);
    }
}
