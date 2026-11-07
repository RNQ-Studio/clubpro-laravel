<?php

namespace App\Support\Enums;

enum DuesPaymentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
