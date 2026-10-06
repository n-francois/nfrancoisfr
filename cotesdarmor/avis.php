<?php
/*
 * Ancienne adresse du formulaire d'avis de /cotesdarmor/ : les avis passent désormais par api/avis.php, commun à tous
 * les événements. Gardée pour une page restée ouverte dans un navigateur.
 */
$EVENEMENT_DEFAUT = 'cotesdarmor-2026';
require dirname(__DIR__) . '/api/avis.php';
