<?php

namespace App\Enums;

enum SuggestedAction: string
{
    case Reply = 'reply';
    case Archive = 'archive';
    case Delete = 'delete';
    case Flag = 'flag';
    case None = 'none';
}
