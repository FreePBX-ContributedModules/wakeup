<?php

// Register FeatureCode - Weather
$fcc = new featurecode('wakeup', 'wakeup');
$fcc->setDescription('Wakeup');
$fcc->setDefault('*62');
$fcc->update();
unset($fcc);

?>
