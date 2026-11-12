<?php

namespace App\Noc\Analysis;

/** Server-selected purpose, never a request/LLM field or operational capability. */
enum SimulationScope: string
{
    case LaboratoryConversation = 'LAB_CONVERSATION';
    case AdministrativeDemo = 'ADMINISTRATIVE_DEMO';

    public function enabled(): bool
    {
        return $this === self::AdministrativeDemo ? app(Contracts::class)->adminDemoEnabled() : app(Contracts::class)->labEnabled();
    }
}
