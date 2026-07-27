<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use App\Services\LicenseSigner;
use Illuminate\Console\Command;

/**
 * Mint an RSA-signed offline .lic from the CLI (AS-DL) — the command-line
 * alternative to the "Generate .lic" button in /super → On-Prem Clients.
 * Writes <KEY>.lic (or --out). Requires the signing key
 * (php artisan smartprs:make-license-keys) on this /super server.
 */
class IssueLicense extends Command
{
    protected $signature = 'smartprs:issue-license
        {--edition=l1 : Edition l1|l2|l3}
        {--company= : Company name (shown on the licence)}
        {--fingerprint= : Machine fingerprint from the client Activation screen (node-lock; blank = any PC)}
        {--seats=0 : Employee seat cap / device_limit; 0 = unlimited}
        {--expires= : Expiry date YYYY-MM-DD (blank = perpetual)}
        {--key= : Licence key (blank = auto SPRS-XXXX-XXXX-XXXX-XXXX)}
        {--out= : Output .lic path (blank = <KEY>.lic in the working dir)}';

    protected $description = 'Generate an RSA-signed offline .lic licence file.';

    public function handle(): int
    {
        $signer = new LicenseSigner();
        if (! $signer->available()) {
            $this->error('Signing key missing at ' . $signer->privateKeyPath()
                . ' — run:  php artisan smartprs:make-license-keys');
            return self::FAILURE;
        }

        $edition = strtolower((string) $this->option('edition'));
        if (! in_array($edition, ['l1', 'l2', 'l3'], true)) {
            $this->error('--edition must be l1, l2 or l3.');
            return self::FAILURE;
        }

        $key = trim((string) $this->option('key')) ?: LicenseService::generateKey();
        $token = $signer->sign([
            'key'          => $key,
            'company'      => $this->option('company'),
            'edition'      => $edition,
            'device_limit' => (int) $this->option('seats'),
            'expires_at'   => trim((string) $this->option('expires')) ?: null,
            'fingerprint'  => trim((string) $this->option('fingerprint')),
        ]);

        $out = trim((string) $this->option('out')) ?: $signer->filename($key);
        file_put_contents($out, $token . "\n");

        $this->newLine();
        $this->info('Licence file written: ' . $out);
        $this->line('  Key:     ' . $key);
        $this->line('  Edition: SmartPRS-' . strtoupper($edition));
        $seats = (int) $this->option('seats');
        $this->line('  Seats:   ' . ($seats > 0 ? $seats : 'unlimited'));
        $this->line('  Expires: ' . (trim((string) $this->option('expires')) ?: 'perpetual'));
        $this->line('  Locked:  ' . (trim((string) $this->option('fingerprint')) !== '' ? 'this machine only' : 'any machine (not node-locked)'));
        $this->newLine();
        $this->line('Give this .lic to the client; they import it on the SmartPRS Activation screen.');

        return self::SUCCESS;
    }
}
