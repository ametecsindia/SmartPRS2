SmartPRS — public images
=========================

HERO BACKGROUND IMAGE
---------------------
Save the landing-page hero photo in THIS folder as:

    hero.png

Full path:
    ...\SmartPRS by Ametecs\smartprs\public\images\hero.png

The landing page (resources/views/landing.blade.php) loads it via
asset('images/hero.png'). The photo is composed with the subject on the
RIGHT and open space on the LEFT, so the intro text sits cleanly on the left
over a dark scrim. Recommended: a wide (landscape) JPG ~1600x1067 or larger.

To use a different file name or path, change hero.image in
App\Http\Controllers\LandingController::defaults() (or via the Landing CMS at
/admin/landing if that field is exposed).

After adding the file: run DEPLOY-SmartPRS.bat (copies public/ across) and
hard-refresh the site root /.
