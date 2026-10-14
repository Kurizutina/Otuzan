<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// "Letters and numbers, 8+" is satisfied by the most guessed passwords there
// are ("password1"), which a login attempt tries first. A short built-in list
// avoids calling an outside breach-check service on every signup. Matched
// case-insensitively, so "Password1" is caught too.
class NotCommonPassword implements ValidationRule
{
    private const COMMON = [
        'password1', 'password12', 'password123', 'password1234', 'passw0rd', 'p@ssw0rd1',
        'qwerty123', 'qwerty1234', 'qwertyuiop1', 'qwerty12345', 'abc12345', 'abcd1234', 'abc123456',
        'a1234567', 'a12345678', '1234abcd', '12345678a', '123456789a', '1q2w3e4r', '1qaz2wsx',
        'iloveyou1', 'iloveyou123', 'welcome1', 'welcome123', 'admin123', 'admin1234', 'letmein123',
        'monkey123', 'dragon123', 'football1', 'baseball1', 'superman1', 'princess1', 'sunshine1',
        'changeme1', 'changeme123', 'test1234', 'test12345', 'user1234', 'asdf1234', 'zxcvbnm123',
        'otuzan123', 'otuzan1234', 'clsu1234', 'clsu12345', 'clsu2026', 'clsu2025',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && in_array(strtolower($value), self::COMMON, true)) {
            $fail('That password is too common. Choose one that is harder to guess.');
        }
    }
}
