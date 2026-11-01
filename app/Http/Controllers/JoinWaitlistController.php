<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\JoinWaitlistRequest;
use App\Jobs\SendMetaEvent;
use App\Jobs\SendSignUpConfirmation;
use App\Models\Subscriber;
use App\Support\AdCampaign;
use App\Support\MetaConversions;
use App\Support\MetaEvents;
use App\Support\MetaVisitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class JoinWaitlistController extends Controller
{
    public function __invoke(JoinWaitlistRequest $request): RedirectResponse
    {
        // Bots get the same response as people, so they can't tell they were caught.
        if ($request->filled('website')) {
            return to_route('thank-you');
        }

        $subscriber = Subscriber::recordSignUp($request->firstName(), $request->mobile(), $request->string('postcode')->value(), $request->ip(), AdCampaign::fromCookie($request));

        // The thank-you page reports the same sign-up from the browser with this ID, so Meta counts it once.
        $leadEventId = (string) Str::uuid();

        if ($subscriber->isSubscribed() && MetaConversions::isEnabled()) {
            SendMetaEvent::dispatch(MetaEvents::lead($subscriber, MetaVisitor::fromRequest($request), $leadEventId, $this->pageUrl($request)));
        }

        return to_route('thank-you')->with([
            'waitlist.first_name' => $request->firstName(),
            'waitlist.lead_event_id' => $subscriber->isSubscribed() ? $leadEventId : null,
            'waitlist.confirmation_coming' => SendSignUpConfirmation::queueFor($subscriber),
        ]);
    }

    /**
     * The page the form was on, without its query string. The Referer header can say anything, so another site's
     * page is replaced with the home page.
     */
    private function pageUrl(JoinWaitlistRequest $request): string
    {
        $page = (string) $request->headers->get('referer');

        if (parse_url($page, PHP_URL_HOST) !== $request->getHost()) {
            return url('/');
        }

        return url((string) parse_url($page, PHP_URL_PATH));
    }
}
