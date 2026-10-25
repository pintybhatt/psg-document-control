<?php

namespace App\Authorization;

enum DocumentResourceType: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
    case Controlled = 'controlled';
}
