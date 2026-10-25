<?php

namespace App\Authorization;

enum NumberingResetPolicy: string
{
    case Annual = 'annual';
    case Continuous = 'continuous';
}
