<?php

namespace App\Support\Enums;

enum CommunityStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
