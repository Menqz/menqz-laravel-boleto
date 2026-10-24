<?php

declare(strict_types=1);

namespace Eduardokum\LaravelBoleto\Contracts\Api;

use DateTimeInterface;
use Eduardokum\LaravelBoleto\Api\DTO\CertificadoRenovado;

interface CertificadoRenovavel
{
    public function renovarCertificado(array $params = []): CertificadoRenovado;

    public function extrairValidadeCertificado(): ?DateTimeInterface;

    public function suportaRenovacaoAutomatica(): bool;
}
