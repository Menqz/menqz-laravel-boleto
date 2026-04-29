<?php

namespace Eduardokum\LaravelBoleto\Api\Banco;

use Illuminate\Support\Arr;
use Eduardokum\LaravelBoleto\Api\AbstractAPI;
use Eduardokum\LaravelBoleto\Api\Exception\CurlException;
use Eduardokum\LaravelBoleto\Api\Exception\HttpException;
use Eduardokum\LaravelBoleto\Api\Exception\UnauthorizedException;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Boleto\Banco\Sicredi as BoletoSicredi;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoAPIContract;

class Sicredi extends AbstractAPI
{
    protected $baseUrl = 'https://api.sicredi.com.br';

    protected $developer_key = null;

    protected $username = null;

    protected $password = null;

    protected $cooperativa = null;

    protected $codigo_beneficiario = null;

    protected $posto = null;

    protected $camposObrigatorios = [
        'developer_key',
        'username',
        'senha',
        'cooperativa',
        'codigoBeneficiario',
        'posto',
    ];

    public function __construct($params = [])
    {
        if (isset($params['ambiente']) && $params['ambiente'] === 'H') {
            $this->baseUrl = 'https://api-h.sicredi.com.br';
        }

        parent::__construct($params);
    }

    public function getDeveloperKey()
    {
        return $this->developer_key;
    }

    public function setDeveloperKey($developerKey)
    {
        $this->developer_key = $developerKey;

        return $this;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function setUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    public function getCooperativa()
    {
        return $this->cooperativa;
    }

    public function setCooperativa($cooperativa)
    {
        $this->cooperativa = $cooperativa;

        return $this;
    }

    public function getCodigoBeneficiario()
    {
        return $this->codigo_beneficiario;
    }

    public function setCodigoBeneficiario($codigoBeneficiario)
    {
        $this->codigo_beneficiario = $codigoBeneficiario;

        return $this;
    }

    public function getPosto()
    {
        return $this->posto;
    }

    public function setPosto($posto)
    {
        $this->posto = $posto;

        return $this;
    }

    protected function headers()
    {
        return array_filter([
            'x-developer-key' => $this->getDeveloperKey(),
            'x-api-key'       => $this->getAccessToken(),
            'username'        => $this->getUsername(),
            'password'        => $this->getSenha(),
        ]);
    }

    protected function oAuth2()
    {
        if ($this->getAccessToken()) {
            return $this;
        }

        $grant = $this->post($this->url('auth'), [
            'username' => $this->getUsername(),
            'password' => $this->getSenha(),
        ])->body;

        if (! isset($grant->accessToken)) {
            throw new ValidationException('Resposta de autenticação Sicredi inválida');
        }

        return $this->setAccessToken($grant->accessToken);
    }

    public function createBoleto(BoletoAPIContract $boleto)
    {
        if (! $boleto instanceof BoletoSicredi) {
            throw new ValidationException('Boleto deve ser instância de ' . BoletoSicredi::class);
        }

        $boleto->setAgencia($this->getCooperativa());
        $boleto->setPosto($this->getPosto());
        $boleto->setCodigoCliente($this->getCodigoBeneficiario());

        $data = $boleto->toAPI();

        $retorno = $this->oAuth2()->post($this->url('create'), $data);

        if (isset($retorno->body->data->dado_boleto->nosso_numero)) {
            $boleto->setNossoNumero($retorno->body->data->dado_boleto->nosso_numero);
        }

        return $boleto;
    }

    public function retrieveNossoNumero($nossoNumero)
    {
        $params = [
            'cooperativa'        => $this->getCooperativa(),
            'posto'              => $this->getPosto(),
            'codigo_beneficiario'=> $this->getCodigoBeneficiario(),
            'nosso_numero'       => $nossoNumero,
        ];

        $response = $this->oAuth2()->get($this->url('show') . '?' . http_build_query($params));

        return $response->body;
    }

    public function retrieveID($id)
    {
        $params = [
            'cooperativa'         => $this->getCooperativa(),
            'posto'               => $this->getPosto(),
            'codigo_beneficiario' => $this->getCodigoBeneficiario(),
            'id_boleto'           => $id,
        ];

        $response = $this->oAuth2()->get($this->url('show') . '?' . http_build_query($params));

        return $response->body;
    }

    public function cancelNossoNumero($nossoNumero, $motivo)
    {
        $params = [
            'cooperativa'         => $this->getCooperativa(),
            'posto'               => $this->getPosto(),
            'codigo_beneficiario' => $this->getCodigoBeneficiario(),
            'nosso_numero'        => $nossoNumero,
            'codigo_motivo_baixa' => $motivo,
        ];

        $response = $this->oAuth2()->post($this->url('cancel'), $params);

        return $response->body;
    }

    public function cancelID($id, $motivo)
    {
        $params = [
            'cooperativa'         => $this->getCooperativa(),
            'posto'               => $this->getPosto(),
            'codigo_beneficiario' => $this->getCodigoBeneficiario(),
            'id_boleto'           => $id,
            'codigo_motivo_baixa' => $motivo,
        ];

        $response = $this->oAuth2()->post($this->url('cancel'), $params);

        return $response->body;
    }

    public function retrieveList($inputedParams = [])
    {
        throw new ValidationException('Método não disponível no banco');
    }

    public function getPdfNossoNumero($nossoNumero)
    {
        throw new ValidationException('Método não disponível no banco');
    }

    public function getPdfID($id)
    {
        throw new ValidationException('Método não disponível no banco');
    }

    private function url($type)
    {
        $urls = [
            'auth'   => 'auth/openapi/v1/token',
            'create' => 'cobranca/v2/boletos',
            'show'   => 'cobranca/v2/boletos',
            'cancel' => 'cobranca/v2/boletos/cancelar',
        ];

        return Arr::get($urls, $type);
    }
}

