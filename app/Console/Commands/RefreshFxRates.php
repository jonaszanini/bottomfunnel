<?php

namespace App\Console\Commands;

use App\Services\WatchingProducts\Fx\FrankfurterFx;
use Illuminate\Console\Command;

class RefreshFxRates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fx:refresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh foreign exchange rates for CAD via Frankfurter (with Bank of Canada fallback)';

    /**
     * Execute the console command.
     */
    public function handle(FrankfurterFx $fx): int
    {
        $this->info('Refreshing daily FX rates...');
        $currencies = ['USD', 'EUR', 'GBP', 'BRL'];

        foreach ($currencies as $currency) {
            $rate = $fx->getRate($currency, 'CAD');
            $this->line("1 {$currency} = {$rate} CAD");
        }

        $this->info('Daily FX rates refreshed successfully.');

        return self::SUCCESS;
    }
}
