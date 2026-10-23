<?php

namespace App\Enums;

enum CompanyRole: string
{
    case Owner = 'owner';
    case Accountant = 'accountant';
    case Staff = 'staff';
    case Auditor = 'auditor';
}
