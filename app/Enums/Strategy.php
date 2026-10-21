<?php

namespace App\Enums;

enum Strategy: string
{
    case BofDirect = 'bof_direct';
    case BofReview = 'bof_review';
    case Robusta = 'robusta';
    case PresellPopup = 'presell_popup';
    case PresellCookie = 'presell_cookie';
    case Quiz = 'quiz';
    case LaunchEvent = 'launch_event';

    public function label(): string
    {
        return match ($this) {
            self::BofDirect => 'BoF Direct',
            self::BofReview => 'BoF Review',
            self::Robusta => 'Robusta',
            self::PresellPopup => 'Pre-sell Pop-up',
            self::PresellCookie => 'Pre-sell Cookie',
            self::Quiz => 'Quiz',
            self::LaunchEvent => 'Launch Event',
        };
    }
}
