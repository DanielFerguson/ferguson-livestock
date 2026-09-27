<?php

it('asks search engines not to index non-production environments', function () {
    $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('allows indexing in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/')->assertHeaderMissing('X-Robots-Tag');
});
