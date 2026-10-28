<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Links are shown to everyone with access, so they must not carry a user name or password. */
class LinkWithoutCredentials implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null)) {
            $fail('Remove the user name or password from the link.');
        }
    }
}
