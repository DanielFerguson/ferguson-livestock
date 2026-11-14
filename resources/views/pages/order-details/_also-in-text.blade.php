{{-- The customer's other orders their order text covered, by reference only. Each order's page is linked from its own
     confirmation email, never from another order's page, so no page can lead anyone to an order that isn't theirs.
     Expects $alsoInText. --}}
@if ($alsoInText->isNotEmpty())
    <section aria-labelledby="also-heading" class="border-t border-gray-100 pt-6 text-base print:hidden">
        <h2 id="also-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Your other orders</h2>
        <p>Our text was about {{ $alsoInText->map->reference()->join(', ', ' and ') }} too. Each has its own page, linked from its confirmation email.</p>
    </section>
@endif
