<?php

namespace App\Http\Controllers;

use App\Services\Tracking\TrackScript;
use Symfony\Component\HttpFoundation\Response;

class TrackScriptController extends Controller
{
    public function __invoke(TrackScript $script): Response
    {
        return response($script->source(), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
