{{-- Posts without JavaScript; validation errors come back to #waitlist with what was typed kept. --}}
@php
    $errorBag = $errors->getBag('waitlist');

    $fields = [
        'first_name' => ['label' => 'First name', 'type' => 'text', 'autocomplete' => 'given-name', 'placeholder' => 'Your first name', 'hint' => null, 'extra' => []],
        'phone' => ['label' => 'Mobile number', 'type' => 'tel', 'autocomplete' => 'tel', 'placeholder' => '0412 345 678', 'hint' => 'We’ll text this number when orders open.', 'extra' => ['inputmode' => 'tel']],
        'postcode' => ['label' => 'Postcode', 'type' => 'text', 'autocomplete' => 'postal-code', 'placeholder' => '3350', 'hint' => 'Helps us plan deliveries around the '.config('shop.delivery.area_name').'.', 'extra' => ['inputmode' => 'numeric', 'maxlength' => '4', 'pattern' => '[0-9]{4}']],
    ];
@endphp

<section id="waitlist" class="bg-cream py-16 lg:py-24">
    <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 lg:grid-cols-2 lg:gap-16">
        <x-section-heading
            eyebrow="Future drops"
            size="md"
            intro="Leave your first name and mobile number, and we’ll send you a text when the next beef-box drop opens."
        >
            Get a text when the next drop opens
        </x-section-heading>

        <div class="border border-forest/10 bg-white p-6 shadow-xl shadow-forest/10 sm:p-10">
            <h3 class="mb-6 font-display text-3xl font-semibold text-forest">Join the wait list</h3>

            @if ($errorBag->any())
                <div class="mb-6 border-l-4 border-red-700 bg-red-50 p-4 text-red-900" role="alert">
                    <p class="font-semibold">Please check the details below.</p>
                </div>
            @endif

            <form method="post" action="{{ route('waitlist.store') }}" class="space-y-5">
                @csrf

                @foreach ($fields as $name => $field)
                    @php
                        $error = $errorBag->first($name);
                        $describedBy = collect([$field['hint'] ? "waitlist-{$name}-hint" : null, $error ? "waitlist-{$name}-error" : null])->filter()->implode(' ');
                    @endphp
                    <div>
                        <label for="waitlist-{{ $name }}" class="mb-2 block text-base font-semibold text-forest">{{ $field['label'] }}</label>
                        <input
                            id="waitlist-{{ $name }}"
                            name="{{ $name }}"
                            type="{{ $field['type'] }}"
                            value="{{ old($name) }}"
                            autocomplete="{{ $field['autocomplete'] }}"
                            placeholder="{{ $field['placeholder'] }}"
                            required
                            @foreach ($field['extra'] as $attribute => $value) {{ $attribute }}="{{ $value }}" @endforeach
                            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                            @if ($error) aria-invalid="true" @endif
                            @class([
                                'w-full border-2 bg-white px-4 py-3.5 text-lg text-gray-900 placeholder:text-gray-500',
                                'border-red-700' => $error,
                                'border-gray-300 focus:border-sage' => ! $error,
                            ])
                        >
                        @if ($field['hint'])
                            <p id="waitlist-{{ $name }}-hint" class="mt-1.5 text-sm text-gray-600">{{ $field['hint'] }}</p>
                        @endif
                        @if ($error)
                            <p id="waitlist-{{ $name }}-error" class="mt-1.5 text-sm font-semibold text-red-800">{{ $error }}</p>
                        @endif
                    </div>
                @endforeach

                {{-- Bots fill in every field; people never see this one. --}}
                <div class="hidden" aria-hidden="true">
                    <label for="waitlist-website">Leave this empty</label>
                    <input id="waitlist-website" name="website" type="text" tabindex="-1" autocomplete="off">
                </div>

                <div>
                    <div class="flex items-start gap-3">
                        <input
                            id="waitlist-sms_consent"
                            name="sms_consent"
                            type="checkbox"
                            value="1"
                            required
                            @checked(old('sms_consent'))
                            @if ($errorBag->has('sms_consent')) aria-invalid="true" aria-describedby="waitlist-sms_consent-error" @endif
                            class="mt-1 h-6 w-6 shrink-0 accent-forest"
                        >
                        <label for="waitlist-sms_consent" class="text-base leading-relaxed text-gray-700">
                            {{ \App\Models\Subscriber::CONSENT_WORDING }} See the <a href="{{ route('privacy') }}" class="font-semibold text-sage underline">privacy page</a>.
                        </label>
                    </div>
                    @if ($errorBag->has('sms_consent'))
                        <p id="waitlist-sms_consent-error" class="mt-1.5 text-sm font-semibold text-red-800">{{ $errorBag->first('sms_consent') }}</p>
                    @endif
                </div>

                <x-button class="w-full text-lg">Join the wait list</x-button>
            </form>

            <p class="mt-5 flex items-center justify-center gap-2 text-sm text-gray-600">
                <x-icon name="shield-check" class="h-4 w-4 text-sage" />
                No spam, ever. Opt out any time.
            </p>
        </div>
    </div>
</section>
