<?php

declare(strict_types=1);

namespace Eduardokum\LaravelBoleto\Api\DTO;

use DateTimeInterface;

final readonly class CertificadoRenovado
{
    public function __construct(
        public string $certificadoCrtPem,
        public string $chavePrivadaKeyPem,
        public ?string $novoClientSecret,
        public DateTimeInterface $dataVencimento,
        public string $tipoFluxo = 'v2-renovacao',
        public array $dadosExtras = [],
    ) {
    }
}
