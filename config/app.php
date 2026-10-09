<?php
// Non-secret application limits. Credentials come only from the environment.
return [
    'checkout_max_minor'=>1_000_000_000,
    'upload_max_bytes'=>4*1024*1024,
    'reservation_minutes'=>30,
];
