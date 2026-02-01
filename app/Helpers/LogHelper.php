<?php

declare(strict_types=1);
namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class LogHelper {
    
    public function logInfo(string $message): void {
    Log::channel('info')->info($message);
}
}