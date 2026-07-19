<?php

namespace App\Events;

use App\Models\XrayRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class XrayReviewed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public XrayRecord $xray
    ) {}
}
