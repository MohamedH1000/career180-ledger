<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require __DIR__.'/Support/DomainFactory.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');
