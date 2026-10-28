<?php

namespace App\Releases;

use RuntimeException;

/** An action on a release was refused; the message is safe to show. */
class ReleaseRefused extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}
