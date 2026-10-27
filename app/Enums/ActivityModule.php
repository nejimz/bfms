<?php

namespace App\Enums;

enum ActivityModule: string
{
    case Company = 'company';
    case Business = 'business';
    case Classification = 'classification';
    case Category = 'category';
    case Location = 'location';
    case Item = 'item';
    case User = 'user';
}
