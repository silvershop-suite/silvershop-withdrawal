<?php

// The global Page / PageController that silvershop/core expects are provided as *scanned* stub classes in
// tests/php/Stub/ (so the SilverStripe class manifest records their ancestry — see the note in Stub/Page.php).
// This bootstrap only needs to hand off to the framework's test bootstrap.
require_once dirname(__DIR__) . '/vendor/silverstripe/framework/tests/bootstrap.php';
