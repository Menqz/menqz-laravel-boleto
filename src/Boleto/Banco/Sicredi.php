<?php

declare(strict_types=1);

namespace Eduardokum\LaravelBoleto\Boleto\Banco;

use Eduardokum\LaravelBoleto\Util;
use Eduardokum\LaravelBoleto\CalculoDV;
use Eduardokum\LaravelBoleto\Boleto\AbstractBoleto;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoAPIContract;

class Sicredi extends AbstractBoleto implements BoletoAPIContract
{
    public function __construct(array $params = [])
    {
        parent::__construct($params);
        $this->addCampoObrigatorio('byte', 'posto');
    }

    /**
     * Local de pagamento
     *
     * @var string
     */
    protected $localPagamento = 'Pagável preferencialmente nas cooperativas de crédito do sicredi';

    /**
     * Código do banco
     *
     * @var string
     */
    protected $codigoBanco = self::COD_BANCO_SICREDI;

    /**
     * Define as carteiras disponíveis para este banco
     *
     * @var array
     */
    protected $carteiras = ['A', '1', '2', '3'];

    /**
     * Espécie do documento, coódigo para remessa
     *
     * @var string
     */
    protected $especiesCodigo240 = [
        'DMI' => '03', // Duplicata Mercantil por Indicação
        'DM'  => '05', // Duplicata Mercantil por Indicação
        'DR'  => '06', // Duplicata Rural
        'NP'  => '12', // Nota Promissória
        'NR'  => '13', // Nota Promissória Rural
        'NS'  => '16', // Nota de Seguros
        'RC'  => '17', // Recibo
        'LC'  => '07', // Letra de Câmbio
        'ND'  => '19', // Nota de Débito
        'DSI' => '99', // Duplicata de Serviço por Indicação
        'OS'  => '99', // Outros
    ];

    /**
     * Espécie do documento, coódigo para remessa
     *
     * @var string
     */
    protected $especiesCodigo400 = [
        'DMI' => 'A', // Duplicata Mercantil por Indicação
        'DM'  => 'A', // Duplicata Mercantil por Indicação
        'DR'  => 'B', // Duplicata Rural
        'NP'  => 'C', // Nota Promissória
        'NR'  => 'D', // Nota Promissória Rural
        'NS'  => 'E', // Nota de Seguros
        'RC'  => 'G', // Recibo
        'LC'  => 'H', // Letra de Câmbio
        'ND'  => 'I', // Nota de Débito
        'DSI' => 'J', // Duplicata de Serviço por Indicação
        'OS'  => 'K', // Outros
    ];

    /**
     * Se possui registro o boleto (tipo = 1 com registro e 3 sem registro)
     *
     * @var bool
     */
    protected $registro = true;

    /**
     * Código do posto do cliente no banco.
     *
     * @var int
     */
    protected $posto;

    /**
     * Byte que compoe o nosso número.
     *
     * @var int
     */
    protected $byte = 2;

    /**
     * Código do cliente (é código do cedente, também chamado de código do beneficiário) é o código do emissor junto ao banco, geralmente é o próprio número da conta sem o dígito verificador.
     * O código do cliente/cedente/beneficiário será diferente desse padrão em casos como quando um cliente bancário faz a migração da sua conta entre agências.
     *
     * @var string
     */
    protected $codigoCliente;

    /**
     * Define se possui ou não registro
     *
     * @param bool $registro
     * @return Sicredi
     */
    public function setComRegistro($registro)
    {
        $this->registro = $registro;

        return $this;
    }

    /**
     * Retorna se é com registro.
     *
     * @return bool
     */
    public function isComRegistro()
    {
        return $this->registro;
    }

    /**
     * Define o posto do cliente
     *
     * @param int $posto
     * @return Sicredi
     */
    public function setPosto($posto)
    {
        $this->posto = $posto;

        return $this;
    }

    /**
     * Retorna o posto do cliente
     *
     * @return int
     */
    public function getPosto()
    {
        return $this->posto;
    }

    /**
     * Define o byte
     *
     * @param int $byte
     *
     * @return Sicredi
     * @throws ValidationException
     */
    public function setByte($byte)
    {
        if ($byte > 9) {
            throw new ValidationException('O byte deve ser compreendido entre 1 e 9');
        }
        $this->byte = $byte;

        return $this;
    }

    /**
     * Retorna o byte
     *
     * @return int
     */
    public function getByte()
    {
        return $this->byte;
    }

    /**
     * Seta o código do cliente.
     *
     * @param mixed $codigoCliente
     *
     * @return Sicredi
     */
    public function setCodigoCliente($codigoCliente)
    {
        $this->codigoCliente = $codigoCliente;

        return $this;
    }

    /**
     * Retorna o codigo do cliente.
     *
     * @return string
     */
    public function getCodigoCliente()
    {
        return $this->codigoCliente;
    }

    /**
     * Retorna o campo Agência/Beneficiário do boleto
     *
     * @return string
     */
    public function getAgenciaCodigoBeneficiario()
    {
        return sprintf('%04s.%02s.%05s', $this->getAgencia(), $this->getPosto(), $this->getCodigoCliente());
    }

    /**
     * Retorna o código da carteira (Com ou sem registro)
     *
     * @return string
     */
    public function getCarteira()
    {
        return $this->carteira == 'A' ? 1 : $this->carteira;
    }

    /**
     * Gera o Nosso Número.
     *
     * @return string
     */
    protected function gerarNossoNumero()
    {
        $ano = $this->getDataDocumento()->format('y');
        $byte = $this->getByte();
        $numero_boleto = Util::numberFormatGeral($this->getNumero(), 5);

        return $ano . $byte . $numero_boleto
            . CalculoDV::sicrediNossoNumero($this->getAgencia(), $this->getPosto(), $this->getCodigoCliente(), $ano, $byte, $numero_boleto);
    }

    /**
     * Método que retorna o nosso numero usado no boleto. alguns bancos possuem algumas diferenças.
     *
     * @return string
     */
    public function getNossoNumeroBoleto()
    {
        return Util::maskString($this->getNossoNumero(), '##/######-#');
    }

    /**
     * Método para gerar o código da posição de 20 a 44
     *
     * @return string
     * @throws ValidationException
     */
    protected function getCampoLivre()
    {
        if ($this->campoLivre) {
            return $this->campoLivre;
        }

        $campoLivre = $this->isComRegistro() ? '1' : '3';
        $campoLivre .= Util::numberFormatGeral($this->getCarteira(), 1);
        $campoLivre .= $this->getNossoNumero();
        $campoLivre .= Util::numberFormatGeral($this->getAgencia(), 4);
        $campoLivre .= Util::numberFormatGeral($this->getPosto(), 2);
        $campoLivre .= Util::numberFormatGeral($this->getCodigoCliente(), 5);
        $campoLivre .= '10';
        $campoLivre .= Util::modulo11($campoLivre);

        return $this->campoLivre .= $campoLivre;
    }

    /**
     * Método onde qualquer boleto deve extender para gerar o código da posição de 20 a 44
     *
     * @param $campoLivre
     *
     * @return array
     */
    public static function parseCampoLivre($campoLivre)
    {
        return [
            'convenio'        => null,
            'agenciaDv'       => null,
            'contaCorrenteDv' => null,
            'codigoCliente'   => substr($campoLivre, 17, 5),
            'carteira'        => substr($campoLivre, 1, 1),
            'nossoNumero'     => substr($campoLivre, 2, 8),
            'nossoNumeroDv'   => substr($campoLivre, 10, 1),
            'nossoNumeroFull' => substr($campoLivre, 2, 9),
            'agencia'         => substr($campoLivre, 11, 4),
            //'contaCorrente' => substr($campoLivre, 17, 5),
        ];
    }

    public function toAPI(string $formato = 'padrao')
    {
        if ($formato !== 'sicredi') {
            $documentoPagador = Util::onlyNumbers((string) $this->getPagador()->getDocumento());
            $tipoPessoa = strlen($documentoPagador) === 14 ? 'J' : 'F';

            $tipoPessoaCampos = [
                'codigo_tipo_pessoa' => $tipoPessoa,
            ];

            if ($tipoPessoa === 'J') {
                $tipoPessoaCampos['numero_cadastro_nacional_pessoa_juridica'] = $documentoPagador;
            } else {
                $tipoPessoaCampos['numero_cadastro_pessoa_fisica'] = $documentoPagador;
            }

            return [
                'data' => [
                    'beneficiario' => [
                        'codigo_beneficiario' => $this->getCodigoCliente(),
                        'cooperativa' => $this->getAgencia(),
                        'posto' => $this->getPosto(),
                    ],
                    'dado_boleto' => [
                        'nosso_numero' => Util::onlyNumbers((string) $this->getNossoNumero()),
                        'valor_titulo' => Util::nFloat($this->getValor(), 2, false),
                        'data_emissao' => $this->getDataDocumento()->format('Y-m-d'),
                        'data_vencimento' => $this->getDataVencimento()->format('Y-m-d'),
                        'carteira' => $this->getCarteira(),
                        'pagador' => [
                            'pessoa' => [
                                'nome_pessoa' => $this->getPagador()->getNome(),
                                'tipo_pessoa' => $tipoPessoaCampos,
                            ],
                            'endereco' => [
                                'logradouro' => $this->getPagador()->getEndereco(),
                                'bairro' => $this->getPagador()->getBairro(),
                                'cidade' => $this->getPagador()->getCidade(),
                                'uf' => $this->getPagador()->getUf(),
                                'cep' => Util::onlyNumbers((string) $this->getPagador()->getCep()),
                            ],
                        ],
                    ],
                ],
            ];
        }

        // Formato 'sicredi' = API REST v3.9 Cobrança Sicredi.
        $pagador = $this->getPagador();
        if ($pagador === null) {
            throw new ValidationException('Pagador do boleto Sicredi nao informado.');
        }

        $documentoPagador = Util::onlyNumbers((string) $pagador->getDocumento());
        if ($documentoPagador === '') {
            throw new ValidationException('CPF/CNPJ do pagador e obrigatorio para envio Sicredi.');
        }
        $lenDoc = strlen($documentoPagador);
        if ($lenDoc !== 11 && $lenDoc !== 14) {
            throw new ValidationException('CPF/CNPJ do pagador invalido. Deve ter 11 (CPF) ou 14 (CNPJ) digitos.');
        }
        $tipoPessoa = $lenDoc === 14 ? 'JURIDICA' : 'FISICA';

        $logradouro = (string) $pagador->getEndereco();
        $bairro = (string) $pagador->getBairro();
        $cidade = (string) $pagador->getCidade();
        $uf = (string) $pagador->getUf();
        $cep = Util::onlyNumbers((string) $pagador->getCep());
        if (trim($logradouro) === '' || trim($bairro) === '' || trim($cidade) === '' || trim($uf) === '' || trim($cep) === '' || strlen($uf) !== 2 || strlen($cep) !== 8) {
            throw new ValidationException(
                'Pagador endereco (logradouro, bairro, cidade, uf[2], cep[8]) sao obrigatorios para envio Sicredi. Preencha todos campos no cadastro do Parceiro.'
            );
        }

        $nossoNumero = Util::onlyNumbers((string) $this->getNossoNumero(false));
        if (! ctype_digit($nossoNumero)) {
            throw new ValidationException('Nosso Numero invalido para envio Sicredi. Apenas digitos numericos.');
        }
        $nnLen = strlen($nossoNumero);
        if ($nnLen !== 8 && $nnLen !== 9) {
            throw new ValidationException(
                'Nosso Numero invalido para envio Sicredi. Carteira 1 = 8 digitos, Carteira 3 = 9 digitos (SEM DV). Informado: ' . $nnLen . ' digitos.'
            );
        }

        $valor = (float) $this->getValor();
        if ($valor <= 0) {
            throw new ValidationException('Valor do boleto invalido.');
        }
        $valorNominal = number_format($valor, 2, '.', '');

        $dataVenc = $this->getDataVencimento();
        $dataEmis = $this->getDataDocumento() ?? $dataVenc;

        $carteira = (string) $this->getCarteira();
        $tipoCobranca = in_array($carteira, ['1', '2', '3', '4', '7'], true) ? $carteira : '1';

        $payload = [
            'nossoNumero'     => $nossoNumero,
            'valorNominal'    => $valorNominal,
            'dataVencimento'  => $dataVenc->format('Y-m-d'),
            'dataEmissao'     => $dataEmis->format('Y-m-d'),
            'tipoCobranca'    => (int) $tipoCobranca,
            'especieDocumento' => 'DM',
            'pagador' => [
                'tipoPessoa' => $tipoPessoa,
                'cpfCnpj'    => $documentoPagador,
                'nome'       => (string) $pagador->getNome(),
                'endereco'   => [
                    'logradouro'  => (string) $logradouro,
                    'bairro'      => (string) $bairro,
                    'cidade'      => (string) $cidade,
                    'uf'          => strtoupper($uf),
                    'cep'         => (string) $cep,
                    'numero'      => (string) ((method_exists($pagador, 'getNumero') && $pagador->getNumero() !== null) ? (string) $pagador->getNumero() : 'S/N'),
                    'complemento' => (string) ((method_exists($pagador, 'getComplemento') && $pagador->getComplemento() !== null) ? (string) $pagador->getComplemento() : ''),
                ],
            ],
        ];

        $nomeSacador = (string) $this->getBeneficiario()->getNome();
        if (trim($nomeSacador) !== '' && method_exists($this, 'getBeneficiario')) {
            $payload['beneficiarioFinal'] = [
                'nome' => $nomeSacador,
            ];
        }

        return $payload;
    }

    public static function fromAPI($boleto, string $formato = 'padrao')
    {
        if ($formato !== 'sicredi') {
            throw new ValidationException('Método fromAPI padrão (V2) ainda não implementado para o banco Sicredi.');
        }

        if (is_array($boleto)) {
            $boleto = (object) $boleto;
        }
        if (! is_object($boleto)) {
            throw new ValidationException('fromAPI sicredi espera objeto/array de resposta.');
        }

        $situacao = isset($boleto->situacao) ? (string) $boleto->situacao : '';
        $mapSituacao = [
            'EM_ABERTO'  => 'a-vencer',
            'EM_SER'     => 'registrado',
            'LIQUIDADO'  => 'pago',
            'PAGO_PARCIAL' => 'pago-parcial',
            'PROTESTADO' => 'protestado',
            'BAIXADO'    => 'baixado',
            'EXPIRADO'   => 'vencido',
            'CANCELADO'  => 'cancelado',
        ];

        $idBoleto = isset($boleto->nossoNumero) ? (string) $boleto->nossoNumero : '';

        return [
            'id_boleto_api'    => $idBoleto,
            'status_cobranca'  => $mapSituacao[$situacao] ?? $situacao,
            'situacao_sicredi' => $situacao,
            'dados_extras'     => $boleto,
        ];
    }
}
