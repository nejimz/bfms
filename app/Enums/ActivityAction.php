<?php

namespace App\Enums;

enum ActivityAction: string
{
    case Added = 'added';
    case Changed = 'changed';
    case Deleted = 'deleted';
}
