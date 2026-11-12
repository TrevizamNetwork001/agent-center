<?php

namespace App\Diagnostics;

enum OperationalIntent: string
{
    case Route = 'ROUTE_PATH_ANALYSIS';
    case Connectivity = 'BASIC_CONNECTIVITY';
    case Web = 'WEB_AVAILABILITY';
    case Dns = 'DNS_RESOLUTION';
    case Latency = 'LATENCY_PACKET_LOSS';
    case Port = 'SERVICE_PORT_CHECK';
    case Slowness = 'GENERAL_SLOWNESS';

    public function label(): string
    {
        return match ($this) {
            self::Route => 'Análise de rota', self::Connectivity => 'Conectividade básica',
            self::Web => 'Disponibilidade HTTPS', self::Dns => 'Resolução DNS',
            self::Latency => 'Latência e perda no destino', self::Port => 'Acesso à porta TCP',
            self::Slowness => 'Investigação inicial de lentidão',
        };
    }

    public static function forProfile(string $profile): ?self
    {
        return match ($profile) {
            'MTR_ICMP', 'MTR_TCP', 'CAMINHO_TRACEROUTE' => self::Route,
            'CONECTIVIDADE_BASICA' => self::Connectivity, 'PORTA_SERVICO' => self::Port,
            'PERDA_LATENCIA' => self::Latency, 'PROBLEMA_DNS' => self::Dns,
            'SITE_NAO_ABRE' => self::Web,
            'SITE_LENTO', 'CONTEUDO_LENTO', 'GOOGLE_YOUTUBE' => self::Slowness,
            default => null,
        };
    }
}
