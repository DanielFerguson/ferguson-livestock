<?php

declare(strict_types=1);

it('asks search engines not to index non-production environments', function (): void {
    $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('allows indexing in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/')->assertHeaderMissing('X-Robots-Tag');
});
