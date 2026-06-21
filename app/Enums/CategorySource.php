<?php

namespace App\Enums;

enum CategorySource: string
{
    case Llm = 'llm';
    case User = 'user';
}
