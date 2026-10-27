<?php

namespace App\Exceptions;

use DomainException;

/**
 * A requested state change is not allowed by the workflow.
 */
class InvalidTransition extends DomainException {}
