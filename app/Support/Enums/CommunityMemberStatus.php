<?php

namespace App\Support\Enums;

enum CommunityMemberStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
}
