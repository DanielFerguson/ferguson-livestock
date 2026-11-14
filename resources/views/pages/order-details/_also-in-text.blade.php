{{-- The customer's other orders their order text covered. Expects $alsoInText. --}}
@if ($alsoInText->isNotEmpty())
    <section aria-labelledby="also-heading" class="border-t border-gray-100 pt-6 text-base print:hidden">
        <h2 id="also-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Your other orders</h2>
        <p>Our text was about these too:</p>
        <ul class="mt-2 list-disc pl-6">
            @foreach ($alsoInText as $other)
                <li><a class="underline" href="{{ $other->pageUrl() }}">Order {{ $other->reference() }}</a></li>
            @endforeach
        </ul>
    </section>
@endif
