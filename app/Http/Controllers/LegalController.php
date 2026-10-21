<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    /**
     * Display the Terms of Service page.
     */
    public function terms(): Response
    {
        return Inertia::render('Legal/TermsOfService');
    }

    /**
     * Display the Privacy Policy page.
     */
    public function privacy(): Response
    {
        return Inertia::render('Legal/PrivacyPolicy');
    }
}
