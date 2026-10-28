<?php

namespace App\Proposals;

use RuntimeException;

/** An action on a proposal was refused; the message is safe to show. */
class ProposalRefused extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}
