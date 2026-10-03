<?php

require __DIR__.'/bootstrap.php';

function chargeCard(): void
{
    throw new RuntimeException('Card declined');
}

chargeCard();
