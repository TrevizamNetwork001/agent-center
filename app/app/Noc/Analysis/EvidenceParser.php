<?php

namespace App\Noc\Analysis;

interface EvidenceParser
{
    public function normalize(array $contract, array $payload): array;
}
