<?php

namespace App\Console\Commands;

use App\Infrastructure\Providers\SandboxHttp;
use App\Infrastructure\Providers\SandboxProviders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncSandboxCatalog extends Command
{
    protected $signature = 'sandbox:catalog {provider : stripe, paypal or mollie}';

    protected $description = 'Provision monthly EUR plans in the selected provider test environment';

    public function handle(): int
    {
        $provider = (string) $this->argument('provider');
        if (! in_array($provider, ['stripe', 'paypal', 'mollie'], true)) {
            $this->error('Select stripe, paypal or mollie.');

            return self::FAILURE;
        }
        $lock = Cache::lock('sandbox:catalog:'.$provider, 180);
        if (! $lock->get()) {
            $this->error('Catalog synchronization is already running.');

            return self::FAILURE;
        }
        try {
            $adapter = app(SandboxProviders::class)->for($provider);
            $hash = app(SandboxHttp::class)->credentialHash($provider);
            foreach (DB::table('prices')->orderBy('id')->get() as $price) {
                $scope = ['provider' => $provider, 'price_id' => $price->id, 'credential_hash' => $hash];
                if (DB::table('sandbox_catalog')->where($scope)->exists()) {
                    continue;
                }
                $key = substr(hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR)), 0, 24);
                $id = $adapter->syncPrice((int) $price->id, $key);
                DB::table('sandbox_catalog')->insert($scope + ['external_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->info('Test catalog is ready for '.$provider.'.');

            return self::SUCCESS;
        } catch (\DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
