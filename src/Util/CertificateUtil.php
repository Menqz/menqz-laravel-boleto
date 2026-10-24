<?php

declare(strict_types=1);

namespace Eduardokum\LaravelBoleto\Util;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

class CertificateUtil
{
    public static function gerarChavePrivadaRSA2048Pem(): string
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg'       => 'sha512',
        ];

        $cnf = self::descobrirConfigOpenSSL();
        if ($cnf !== null) {
            $config['config'] = $cnf;
        }

        $resource = openssl_pkey_new($config);
        if (! $resource) {
            throw new \RuntimeException('Falha ao gerar chave privada RSA 2048: '.self::pegarUltimoErroOpenSSL());
        }

        $pem = '';
        $cnf !== null
            ? openssl_pkey_export($resource, $pem, null, ['config' => $cnf])
            : openssl_pkey_export($resource, $pem);

        if (trim($pem) === '') {
            throw new \RuntimeException('Falha ao exportar chave privada PEM: '.self::pegarUltimoErroOpenSSL());
        }

        return $pem;
    }

    public static function gerarCSR(
        string $cn,
        string $ou,
        string $l,
        string $st,
        string $c,
        string $privKeyPem,
        string $digestAlg = 'sha512',
    ): string {
        $dn = [
            'CN' => self::sanitizarSubjectAscii($cn),
            'OU' => self::sanitizarSubjectAscii($ou),
            'L'  => self::sanitizarSubjectAscii($l),
            'ST' => self::sanitizarSubjectAscii($st),
            'C'  => self::sanitizarSubjectAscii($c),
        ];

        foreach ($dn as $campo => $valor) {
            if ($campo !== 'CN' && trim((string) $valor) === '') {
                unset($dn[$campo]);
            }
        }

        if (trim((string) $dn['CN']) === '') {
            throw new \InvalidArgumentException('Campo CN (client_id) do Subject do CSR não pode ser vazio.');
        }

        $privKey = openssl_pkey_get_private($privKeyPem);
        if (! $privKey) {
            throw new \InvalidArgumentException('Chave privada fornecida para gerar CSR é inválida: '.self::pegarUltimoErroOpenSSL());
        }

        $opcoesCSR = [
            'digest_alg' => $digestAlg,
        ];

        $cnf = self::descobrirConfigOpenSSL();
        if ($cnf !== null) {
            $opcoesCSR['config'] = $cnf;
        }

        $csr = openssl_csr_new($dn, $privKey, $opcoesCSR);
        if (! $csr) {
            throw new \RuntimeException('Falha ao gerar CSR: '.self::pegarUltimoErroOpenSSL());
        }

        $pem = '';
        openssl_csr_export($csr, $pem);

        if (trim($pem) === '') {
            throw new \RuntimeException('Falha ao exportar CSR PEM: '.self::pegarUltimoErroOpenSSL());
        }

        return $pem;
    }

    public static function extrairValidadeCertificado(string $crtPemOuPath): ?DateTimeImmutable
    {
        try {
            if ($crtPemOuPath === '') {
                return null;
            }

            $pem = self::carregarConteudoPEMCertificado($crtPemOuPath);
            if ($pem === null) {
                return null;
            }

            $parseado = @openssl_x509_parse($pem);
            if (! is_array($parseado) || ! isset($parseado['validTo_time_t'])) {
                return null;
            }

            $timestamp = (int) $parseado['validTo_time_t'];
            if ($timestamp <= 0) {
                return null;
            }

            return (new DateTimeImmutable())->setTimestamp($timestamp);
        } catch (Throwable) {
            return null;
        }
    }

    public static function extrairCNDoCSR(string $csrPem): ?string
    {
        try {
            if (trim($csrPem) === '') {
                return null;
            }

            $subject = @openssl_csr_get_subject($csrPem);

            return is_array($subject) && isset($subject['CN'])
                ? (string) $subject['CN']
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function sanitizarSubjectAscii(string $valor): string
    {
        if ($valor === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            try {
                $valor = \Normalizer::normalize($valor, \Normalizer::NFD);
            } catch (Throwable) {
            }
        }

        $valor = (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $valor);
        $valor = (string) preg_replace('/[^'."\x20-\x7E".']+/', '', $valor);

        return trim($valor);
    }

    public static function validarPEMCertificado(string $crtPem): bool
    {
        try {
            if (trim($crtPem) === '') {
                return false;
            }

            $resource = @openssl_x509_read($crtPem);
            if (! $resource) {
                return false;
            }

            $info = @openssl_x509_parse($resource);

            return is_array($info) && isset($info['validTo_time_t']);
        } catch (Throwable) {
            return false;
        }
    }

    public static function calcularDiasAteVencer(
        DateTimeInterface $validade,
        ?DateTimeInterface $agora = null,
    ): int {
        $agora ??= new DateTimeImmutable();

        $vencimentoTs = $validade->getTimestamp();
        $agoraTs = $agora->getTimestamp();

        $diffSegundos = $vencimentoTs - $agoraTs;

        return (int) floor($diffSegundos / 86400);
    }

    private static function carregarConteudoPEMCertificado(string $crtPemOuPath): ?string
    {
        $aparentaPEM = str_starts_with(trim($crtPemOuPath), '-----BEGIN CERTIFICATE-----');

        if ($aparentaPEM) {
            return trim($crtPemOuPath);
        }

        try {
            if (strlen($crtPemOuPath) <= 1024 && is_file($crtPemOuPath) && is_readable($crtPemOuPath)) {
                $conteudo = file_get_contents($crtPemOuPath);

                return is_string($conteudo) && trim($conteudo) !== ''
                    ? trim($conteudo)
                    : null;
            }
        } catch (Throwable) {
        }

        if (trim($crtPemOuPath) !== '' && str_contains($crtPemOuPath, 'CERTIFICATE')) {
            return trim($crtPemOuPath);
        }

        return null;
    }

    private static function descobrirConfigOpenSSL(): ?string
    {
        $caminhos = [];

        $envConf = getenv('OPENSSL_CONF');
        if (is_string($envConf) && $envConf !== '') {
            $caminhos[] = $envConf;
        }

        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $dirPhp = dirname(PHP_BINARY);
            $caminhos[] = $dirPhp.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
            $caminhos[] = $dirPhp.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'openssl.cnf';
            $caminhos[] = $dirPhp.DIRECTORY_SEPARATOR.'openssl.cnf';
            $caminhos[] = dirname($dirPhp).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        }

        $caminhos[] = '/etc/ssl/openssl.cnf';
        $caminhos[] = '/etc/pki/tls/openssl.cnf';
        $caminhos[] = '/usr/local/ssl/openssl.cnf';
        $caminhos[] = '/usr/lib/ssl/openssl.cnf';

        foreach ($caminhos as $caminho) {
            try {
                if (is_string($caminho) && $caminho !== '' && is_file($caminho) && is_readable($caminho)) {
                    return $caminho;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private static function pegarUltimoErroOpenSSL(): string
    {
        $erros = [];
        while ($msg = openssl_error_string()) {
            $erros[] = $msg;
        }

        return $erros !== []
            ? implode(' | ', $erros)
            : '(sem detalhes adicionais do OpenSSL)';
    }
}
