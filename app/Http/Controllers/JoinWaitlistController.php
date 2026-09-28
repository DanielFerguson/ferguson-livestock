<?php

namespace App\Http\Controllers;

use App\Http\Requests\JoinWaitlistRequest;
use App\Models\Subscriber;
use Illuminate\Http\RedirectResponse;

class JoinWaitlistController extends Controller
{
    public function __invoke(JoinWaitlistRequest $request): RedirectResponse
    {
        // Bots get the same response as people, so they can't tell they were caught.
        if ($request->filled('website')) {
            return to_route('thank-you');
        }

        Subscriber::recordSignUp($request->firstName(), $request->mobile(), $request->string('postcode')->value(), $request->ip());

        return to_route('thank-you')->with('waitlist.first_name', $request->firstName());
    }
}
