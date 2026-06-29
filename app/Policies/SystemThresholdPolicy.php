<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesMasterData;

class SystemThresholdPolicy
{
    use AuthorizesMasterData;
}
