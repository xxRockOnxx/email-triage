<?php

namespace App\Enums;

enum CategorySource: string
{
    case Gmail = 'gmail';
    case Llm = 'llm';
    case User = 'user';
}
