<?php

namespace App\Enums;

enum EmailEventType: string
{
    case Delivered = 'delivered';
    case Open = 'open';
    case Click = 'click';
    case HardBounce = 'hard_bounce';
    case SoftBounce = 'soft_bounce';
    case Complaint = 'complaint';
    case Unsubscribe = 'unsubscribe';
    case Failed = 'failed';
}
