<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesMasterData;

class SitePolicy
{
    use AuthorizesMasterData;
}
