<?php

it('renders the homepage in a real browser without JavaScript errors', function () {
    visit('/')
        ->assertSee('Ferguson Livestock')
        ->assertNoJavaScriptErrors();
});
