<?php

test('the application returns a successful response', function () {
    $this->withoutVite()
        ->get('/')
        ->assertStatus(200);
});
