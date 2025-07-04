<?php

declare(strict_types=1);

use Asika\UnitConverter\Duration;

include __DIR__ . '/../vendor/autoload.php';

$weight = new \Asika\UnitConverter\Weight(100, 'N');
echo $weight->format(unit: 'kg', scale: 4);
echo "\n";
$weight = $weight->withGravityAcceleration(1.62); // The gravity acceleration on the Moon
echo $weight->format(unit: 'kg', scale: 4);
