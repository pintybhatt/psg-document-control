<?php

namespace App\Authorization;

enum NumberingPurpose: string
{
    case InternalRegister = 'internal_register';
    case OfficialOutgoing = 'official_outgoing';
}
