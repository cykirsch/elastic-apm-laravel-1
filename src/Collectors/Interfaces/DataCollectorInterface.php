<?php

namespace AG\ElasticApmLaravel\Collectors\Interfaces;

use Illuminate\Support\Collection;

interface DataCollectorInterface
{
    public function collect(string $transaction_name): Collection;

    public static function getName(): string;
}
