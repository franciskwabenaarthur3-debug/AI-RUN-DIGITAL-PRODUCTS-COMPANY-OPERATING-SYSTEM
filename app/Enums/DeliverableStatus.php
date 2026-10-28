<?php

namespace App\Enums;

enum DeliverableStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Delivered = 'delivered';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
