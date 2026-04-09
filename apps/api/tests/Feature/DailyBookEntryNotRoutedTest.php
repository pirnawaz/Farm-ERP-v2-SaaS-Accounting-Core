<?php

namespace Tests\Feature;

use App\Http\Controllers\DailyBookEntryController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Daily book HTTP posting was never wired; PostingService::postDailyBookEntry did not exist.
 * Active operational flow is OperationalTransaction + PostingService::postOperationalTransaction.
 */
class DailyBookEntryNotRoutedTest extends TestCase
{
    public function test_no_api_route_uses_daily_book_entry_controller(): void
    {
        $offending = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction();
            $controller = $action['controller'] ?? null;
            if ($controller === null) {
                continue;
            }
            if (is_string($controller)) {
                if (str_contains($controller, 'DailyBookEntryController')) {
                    $offending[] = $route->uri().' → '.$controller;
                }
                continue;
            }
            if (is_array($controller) && ($controller[0] ?? null) === DailyBookEntryController::class) {
                $offending[] = $route->uri().' → '.DailyBookEntryController::class;
            }
        }

        $this->assertSame(
            [],
            $offending,
            'DailyBookEntryController must not be registered until daily book is wired end-to-end.'
        );
    }
}
