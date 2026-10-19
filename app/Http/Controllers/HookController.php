<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Services\Tracking\PostbackParser;
use App\Services\Tracking\PostbackRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HookController extends Controller
{
    public function __invoke(Request $request, string $platform, string $secret, PostbackParser $parser, PostbackRecorder $recorder): JsonResponse
    {
        if (! array_key_exists($platform, config('flowhub.platforms'))) {
            abort(404);
        }

        $track = Track::query()->where('postback_secret', $secret)->where('platform', $platform)->first();
        if (! $track) {
            abort(404);
        }

        $raw = $request->getContent();
        if ($raw === '') {
            $raw = http_build_query($request->request->all());
        }
        if ($raw === '') {
            $raw = $request->getQueryString() ?? '';
        }
        $fields = array_merge($request->query->all(), $request->request->all());
        $parsed = $parser->parse($raw, $fields, $track->postback_secret, $request->headers->all());
        if ($parsed->signaturePresent && ! $parsed->signatureValid) {
            return response()->json(['message' => 'Postback signature did not match this Track.'], 401);
        }

        $recorder->record($track, $raw, $parsed);

        return response()->json(['ok' => true]);
    }
}
