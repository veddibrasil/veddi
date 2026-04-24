<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('root redirects to marketing site', function () {
    $response = $this->get('/');

    $response->assertRedirect();
});
