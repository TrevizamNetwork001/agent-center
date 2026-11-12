<?php

namespace App\Diagnostics;

enum OperationalIntent: string
{
    case DeviceBgp = 'BGP_SESSION_ANALYSIS';
    case DeviceRouting = 'ROUTING_TABLE_LOOKUP';
    case DeviceOptical = 'OPTICAL_SIGNAL_ANALYSIS';
    case DeviceInterfaces = 'DEVICE_INTERFACE_ANALYSIS';
    case DeviceCounters = 'DEVICE_INTERFACE_COUNTERS_ANALYSIS';
    case DeviceResources = 'DEVICE_RESOURCES_ANALYSIS';
    case DeviceIdentity = 'DEVICE_IDENTIFICATION_ANALYSIS';
    case DeviceAlarms = 'DEVICE_ALARMS_ANALYSIS';
    case DevicePppoe = 'PPPOE_SESSION_ANALYSIS';
    case DeviceOnu = 'ONU_STATUS_ANALYSIS';
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
            self::DeviceBgp => 'Sessões BGP no equipamento', self::DeviceRouting => 'Tabela de roteamento do equipamento',
            self::DeviceOptical => 'Potência óptica das ONUs', self::DeviceInterfaces => 'Interfaces do equipamento',
            self::DeviceCounters => 'Contadores de interfaces', self::DeviceResources => 'CPU e memória', self::DeviceIdentity => 'Identificação da plataforma', self::DeviceAlarms => 'Alarmes documentados',
            self::DevicePppoe => 'Sessões PPPoE', self::DeviceOnu => 'Estado das ONUs',
            self::Route => 'Análise de rota', self::Connectivity => 'Conectividade básica',
            self::Web => 'Disponibilidade HTTPS', self::Dns => 'Resolução DNS',
            self::Latency => 'Latência e perda no destino', self::Port => 'Acesso à porta TCP',
            self::Slowness => 'Investigação inicial de lentidão',
        };
    }

    public function isDevice(): bool
    {
        return in_array($this, [self::DeviceBgp, self::DeviceRouting, self::DeviceOptical, self::DeviceInterfaces, self::DeviceCounters, self::DeviceResources, self::DeviceIdentity, self::DeviceAlarms, self::DevicePppoe, self::DeviceOnu], true);
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
