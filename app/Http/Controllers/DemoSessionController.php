<?php

namespace App\Http\Controllers;

use App\Application\Review\DemoReviewConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class DemoSessionController extends Controller
{
    public function create(DemoReviewConfiguration $demo): View
    {
        $demo->ensureEnabled();

        return view('review.demo');
    }

    public function store(Request $request, DemoReviewConfiguration $demo): RedirectResponse
    {
        $demo->ensureEnabled();
        $unsupported = array_diff(array_keys($request->all()), ['_token']);

        if ($unsupported !== []) {
            throw ValidationException::withMessages([
                (string) reset($unsupported) => 'This field is not supported.',
            ]);
        }

        Auth::guard('web')->login($demo->user());
        $request->session()->regenerate();

        return redirect()->route('opportunities.index');
    }
}
