<?php

it('renders the home page with the site name', function () {
    $this->withoutVite()
        ->get('/')
        ->assertOk()
        ->assertSee('n0glemand');
});
