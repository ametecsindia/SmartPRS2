SmartPRS On-Prem PATCH — 11 Aug 2026
====================================
Only the files changed since your installed build. Applying it does NOT touch
your database or .env — no data loss.

APPLY (client server):
 1. From the SmartPRS app root (where 'artisan' is), extract this zip and choose
    "overwrite existing files". Paths line up 1:1 (app\, config\, public\, storage\).
 2. Run:  php artisan optimize:clear
 3. Hard-refresh the browser (Ctrl+F5).

FIXES: Directory-blank-on-fresh-install; one-licence=one-machine fraud lock
(hardware fingerprint + no floating .lic + server strict block); .lic online
activation recognised; IIS web.config; storage skeleton; hardened installer.

IMPORTANT — also update the licence SERVER (smartprs.com), or the one-machine
lock is not enforced for online activation. Deploy there too, then
'php artisan config:clear':
    app/Http/Controllers/UpdateServerController.php
    app/Services/LicenseService.php
    config/smartprs.php
Keep SMARTPRS_OFFLINE_LIC=false on clients. Re-activate once (fingerprint value
changed): release the old binding in /super first.

Support: ejaz@ametecsindia.com  WhatsApp 9000098877
