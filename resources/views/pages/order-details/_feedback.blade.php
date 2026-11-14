{{-- Stars and a comment, at the bottom of the page. Expects $order with its feedback. --}}
@php
    $errorBag = $errors->getBag('feedback');
    $rating = (int) old('rating', $order->feedback?->rating);
    $comment = old('comment', $order->feedback?->comment);
@endphp

<section id="feedback" aria-labelledby="feedback-heading" class="border border-forest/5 bg-white p-8 shadow-xl">
    <h2 id="feedback-heading" class="mb-2 font-display text-2xl font-semibold text-forest">How did we do?</h2>
    <p class="mb-5">We’re a small family farm, so every bit of feedback helps. Only we see it.</p>

    @if (session('order-page.feedback'))
        <p class="mb-5 border-l-4 border-sage bg-mint/10 p-4 text-forest" role="status">{{ session('order-page.feedback') }}</p>
    @endif

    <form method="post" action="{{ route('orders.feedback', ['customerOrder' => $order->access_token]) }}" class="space-y-5">
        @csrf

        <fieldset @if ($errorBag->has('rating')) aria-describedby="feedback-rating-error" @endif>
            <legend class="mb-2 font-semibold text-forest">Your rating</legend>

            <div class="flex flex-wrap gap-2">
                @foreach (range(1, 5) as $stars)
                    <label class="cursor-pointer">
                        <input type="radio" name="rating" value="{{ $stars }}" @checked($rating === $stars) class="peer sr-only">
                        <span class="inline-flex min-h-12 items-center gap-1 border border-forest/20 px-4 text-forest peer-checked:bg-forest peer-checked:text-cream peer-focus-visible:ring-2 peer-focus-visible:ring-sage">
                            {{ $stars }}
                            <x-svg-icon name="star" />
                            <span class="sr-only">{{ $stars === 1 ? 'star' : 'stars' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            @if ($errorBag->has('rating'))
                <p id="feedback-rating-error" class="mt-2 text-red-800">{{ $errorBag->first('rating') }}</p>
            @endif
        </fieldset>

        <div>
            <label for="feedback-comment" class="mb-2 block font-semibold text-forest">Anything you’d like to tell us? <span class="font-normal text-gray-600">(optional)</span></label>
            <textarea id="feedback-comment" name="comment" rows="4" maxlength="2000" class="w-full border border-forest/20 bg-white p-3 focus:border-sage focus:outline-none" @if ($errorBag->has('comment')) aria-describedby="feedback-comment-error" aria-invalid="true" @endif>{{ $comment }}</textarea>

            @if ($errorBag->has('comment'))
                <p id="feedback-comment-error" class="mt-2 text-red-800">{{ $errorBag->first('comment') }}</p>
            @endif
        </div>

        <x-button>{{ $order->feedback === null ? 'Send feedback' : 'Update feedback' }}</x-button>
    </form>
</section>
