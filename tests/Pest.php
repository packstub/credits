<?php

use Packstub\Credits\Tests\TestCase;
use Packstub\Credits\Tests\UuidTestCase;

pest()->extend(TestCase::class)->in('Feature', 'Security');
pest()->extend(UuidTestCase::class)->in('Uuid');
