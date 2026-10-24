# Renovação Automática de Certificado Digital (Módulo Genérico + Itaú)

Documentação técnica do módulo de renovação de certificados digitais fornecido pelo componente `menqz-laravel-boleto`. O componente é compatível com Laravel 6 a 12; o módulo de renovação requer PHP ≥ 8.2 (uso de `readonly class`) e utiliza exclusivamente a extensão nativa `ext-openssl`.

---

## 1. Visão Geral

O módulo implementa a renovação automática programada de certificados digitais de cliente (mTLS) utilizados em APIs bancárias. A primeira integração oficial é o Banco Itaú (341), que usa a API oficial v2 `/seguranca/v2/certificado/renovacao`, dispensando intervenção humana.

### Prazos de Validade (Itaú, conforme CA/Browser Forum)

| Início da vigência da regra | Validade máxima do certificado emitido | Antecedência padrão de disparo | Janela aceita pela API Itaú v2 |
|:--------------------------- |:-------------------------------------- |:------------------------------ |:------------------------------ |
| 15/03/2026                  | **200 dias**                           | **7 dias antes** (padrão)       | **≤ 30 dias antes**            |
| 15/03/2027                  | **100 dias**                           | idem                            | idem                           |
| 15/03/2029                  | **47 dias**                            | idem                            | idem                           |

A renovação é aceita pela API somente quando faltam 30 dias ou menos para o vencimento. A antecedência de disparo (7 dias por padrão) é um parâmetro da aplicação e deve ser sempre menor ou igual a 30.

---

## 2. Arquitetura Genérica (Interface Segregation + SOLID)

A arquitetura mantém a interface `Contracts/Api/Api.php` inalterada, preservando o Princípio da Substituição de Liskov (LSP). Bancos que não suportam renovação automática (atualmente todos, exceto o Itaú) não precisam implementar nada novo.

### Estrutura de Classes

```
menqz-laravel-boleto/src/
├── Contracts/Api/CertificadoRenovavel.php     # Interface genérica implementada apenas por bancos que suportam renovação
├── Api/DTO/CertificadoRenovado.php            # Value Object imutável (readonly class) com o resultado da renovação
├── Util/CertificateUtil.php                   # Helpers OpenSSL puros (stateless, sem acesso a banco)
└── Api/Banco/Itau.php                         # Implementação concreta Itaú 341: estende AbstractAPI e implementa CertificadoRenovavel
```

### 2.1 `Contracts/Api/CertificadoRenovavel.php` (Interface)

Contrato implementado exclusivamente pelos bancos que oferecem renovação automática:

```php
interface CertificadoRenovavel
{
    /** Executa o fluxo completo de renovação e retorna o DTO com o novo par CRT + KEY e as datas. */
    public function renovarCertificado(array $params = []): CertificadoRenovado;

    /** Extrai a validade atual (UTC) diretamente do certificado .crt em uso; retorna null quando não for possível. */
    public function extrairValidadeCertificado(): ?DateTimeInterface;

    /** Retorna true quando o banco suporta renovação automática integral (atualmente: Itaú 341). */
    public function suportaRenovacaoAutomatica(): bool;
}
```

A aplicação deve verificar `instanceof` antes de invocar a renovação:

```php
$api = /* instância do banco obtida pela aplicação */;
if (! $api instanceof CertificadoRenovavel) {
    // Banco não suporta renovação automática: ignorar e registrar em log.
    return;
}
```

### 2.2 `Api/DTO/CertificadoRenovado.php` (Value Object)

Objeto de retorno imutável (`readonly class`, PHP 8.3):

| Campo                 | Tipo                | Descrição                                                                                                                          |
| --------------------- | ------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `certificadoCrtPem`   | `string`            | Novo certificado .crt em formato PEM completo (BEGIN/END).                                                                          |
| `chavePrivadaKeyPem`  | `string`            | Nova chave privada .key em formato PEM. O conteúdo nunca deve ser registrado em log; para correlacionar, registrar apenas o hash SHA-256. |
| `novoClientSecret`    | `?string`           | `null` no fluxo Itaú v2, pois o secret não é rotacionado. Fluxos específicos futuros podem devolver uma string nova.                |
| `dataVencimento`      | `DateTimeInterface` | Data/hora em UTC de expiração do novo certificado. Utilizada para atualizar o campo `DataVencimentoCertificado`.                    |
| `tipoFluxo`           | `string`            | `'v2-renovacao'` (Itaú atual). Permite auditar qual fluxo foi utilizado.                                                            |
| `dadosExtras`         | `array`             | Auditoria opcional: código HTTP, request id, timestamps, resumo do body, etc.                                                       |

### 2.3 `Util/CertificateUtil.php` (Helpers Puros)

Biblioteca estática sem dependências, reutilizável em qualquer contexto:

```php
use Eduardokum\LaravelBoleto\Util\CertificateUtil;

// 1. Gera uma nova chave privada RSA de 2048 bits.
$novaKey = CertificateUtil::gerarChavePrivadaRSA2048Pem();

// 2. Gera o CSR (certificate signing request) assinado em SHA-512 — compatível com o Itaú.
$csr = CertificateUtil::gerarCSR(
    cn: 'd49e8b26-....client-id-uuid',   // O CN deve ser igual ao client_id (UUID).
    ou: 'SISTEMA-COBRANCA',
    l:  'SAO PAULO',
    st: 'SP',
    c:  'BR',
    privKeyPem: $novaKey,
    digestAlg: 'sha512',
);

// 3. Extrai a validade a partir de um .crt (aceita caminho de arquivo ou conteúdo PEM bruto).
$expiraEm = CertificateUtil::extrairValidadeCertificado(storage_path('app/certificados/itau_341_1.crt'));

// 4. Valida se um conteúdo é um certificado PEM.
CertificateUtil::validarPEMCertificado(file_get_contents($path)); // bool

// 5. Calcula os dias restantes até o vencimento (valor negativo indica certificado expirado).
$diasRestantes = CertificateUtil::calcularDiasAteVencer($expiraEm);

// 6. Extrai o CN do CSR gerado (validação defensiva antes do envio à API).
$cnGerado = CertificateUtil::extrairCNDoCSR($csr);
```

A sanitização de Subject remove caracteres não-ASCII e acentos via `Normalizer::NFD` e regex `[\x20-\x7E]`, pois o OpenSSL não aceita acentuação.

---

## 3. Exemplo de Uso: Itaú 341

```php
<?php
declare(strict_types=1);

use Eduardokum\LaravelBoleto\Api\Banco\Itau;
use Eduardokum\LaravelBoleto\Contracts\Api\CertificadoRenovavel;
use Eduardokum\LaravelBoleto\Util\CertificateUtil;

require __DIR__.'/vendor/autoload.php';

$api = new Itau([
    'client_id'         => 'd49e8b26-05b9-4a2c-9a4d-xxxxxxxxxxxx',   // UUID — também é o CN do CSR
    'client_secret'     => '****************************************',
    'certificado'       => storage_path('app/certificados/itau_341_1.crt'),   // Certificado atual válido
    'certificadoChave'  => storage_path('app/certificados/itau_341_1.key'),   // Chave atual
    'storage_path'      => storage_path('app/certificados'),
    'ambiente'          => 'P',                                                 // 'H' = homologação (sandbox); 'P' = produção
    'cnpj'              => '00000000000191',
    'conta'             => '1234567',
    'id_beneficiario'   => '123412345671',
    'carteira'          => '175',
    // Parâmetros exclusivos da renovação, aplicados ao Subject do CSR (todos opcionais).
    'ou_app_certificado' => 'SISTEMA-COBRANCA',          // OU (Organizational Unit)
    'cidade_empresa'     => 'SAO PAULO',                 // L (Locality Name), sem acentos
    'uf_empresa'         => 'SP',                        // ST (State), duas letras
]);

// FASE 0 — Validar suporte e extrair a validade atual.
if (! $api instanceof CertificadoRenovavel || ! $api->suportaRenovacaoAutomatica()) {
    throw new RuntimeException('Banco não suporta renovação automática.');
}

$validadeAtual = $api->extrairValidadeCertificado();
echo "Validade atual: ".($validadeAtual ? $validadeAtual->format('d/m/Y') : 'N/A')."\n";
echo "Dias restantes: ".($validadeAtual ? CertificateUtil::calcularDiasAteVencer($validadeAtual) : 'N/A')."\n";

// FASE 1 — Executar a renovação v2.
// Regra da API Itaú: a operação só é aceita quando faltam ≤ 30 dias para o vencimento.
// Fora dessa janela, a validação local lança ValidationException antes de chamar a API
// (a API Itaú, se fosse chamada, responderia com o erro C700).
try {
    $dto = $api->renovarCertificado();

    echo "\nRenovação concluída (fluxo: {$dto->tipoFluxo})\n";
    echo "Nova validade: {$dto->dataVencimento->format('d/m/Y')}\n";

    // Os arquivos novos devem ser gravados imediatamente no storage, com permissão 0640 na chave privada.
    $pastaStorage = storage_path('app/certificados');
    $novoCrtPath = $pastaStorage.'/itau_341_1_novo.crt';
    $novoKeyPath = $pastaStorage.'/itau_341_1_novo.key';
    file_put_contents($novoCrtPath, $dto->certificadoCrtPem);
    file_put_contents($novoKeyPath, $dto->chavePrivadaKeyPem);
    chmod($novoKeyPath, 0640);

    // Hash de auditoria: o conteúdo da chave privada nunca deve ser registrado em log.
    echo "hash da nova key (SHA-256): ".hash('sha256', $dto->chavePrivadaKeyPem)."\n";
} catch (\Eduardokum\LaravelBoleto\Exception\ValidationException $e) {
    echo "Falha: ".$e->getMessage()."\n";
}
```

### 3.1 Fluxo Interno da Renovação Itaú v2 (`renovarCertificado()`)

1. Validação defensiva: verifica os campos obrigatórios e se o certificado atual está dentro da janela de ≤ 30 dias, evitando o erro C700 da API Itaú.
2. Geração do novo par: chave privada RSA de 2048 bits e CSR com o subject `CN={client_id UUID}, OU=app, L=cidade, ST=UF, C=BR`.
3. Auto-validação do CN: extrai o CN do CSR recém-gerado e assegura que seja idêntico ao `client_id` antes de efetuar a chamada, evitando o erro C800.
4. Obtenção do access_token no STS via `oAuth2()`, o mesmo método usado pelo Cash Management (private_key_jwt RS256 com mTLS pelo certificado atual, ainda válido).
5. Chamada mTLS com `POST` e body `text/plain` para `https://sts.itau.com.br/seguranca/v2/certificado/renovacao`, com os cabeçalhos:

   ```
   Authorization: Bearer {access_token_sts}
   Content-Type:  text/plain
   x-itau-force-cert: true
   ```

   O body é o CSR em PEM puro (sem `json_encode`), enviado por cURL manual para garantir o corpo bruto.

6. Parse da resposta HTTP 200: extrai o certificado PEM com múltiplas estratégias (marcadores BEGIN/END; reconstrução por wordwrap quando o conteúdo vem compactado).
7. Validação do novo certificado: confirma que o PEM é legível e que a nova validade é estritamente maior que a atual, garantindo que a renovação não regrediu.
8. Retorno do DTO com `certificadoCrtPem`, `chavePrivadaKeyPem` e `dataVencimento`, para que a aplicação persista os valores no banco/storage.

### 3.2 Principais Erros e Tratamento

| Erro / Exception | Causa provável | Ação recomendada |
|:---------------- |:-------------- |:---------------- |
| "API Itaú v2 só permite renovação 30 dias antes…" | Certificado atual ainda válido por mais de 30 dias. | Não é erro. A execução diária apenas aguarda o início da janela e volta a tentar no dia adequado. |
| "CN do CSR gerado difere do client_id…" | UUID truncado ou `client_id` inválido. | Validar o campo `APIClientID` no cadastro da carteira. Não deve ocorrer em produção. |
| "Falha ao obter access_token STS Itaú" | Certificado/chave atuais expirados ou `client_secret` desatualizado. | Urgência alta. A aplicação envia e-mail técnico automaticamente. |
| "HTTP 400" no POST de renovação | Cabeçalho `x-itau-force-cert` ausente ou CSR malformado. | Conferir a versão do componente (o cabeçalho é garantido pela classe). |
| "Resposta da API Itaú de renovação veio vazia" | HTTP 500 do gateway Itaú. | Nova tentativa automática no dia seguinte; alerta ao técnico se persistir por dois dias consecutivos. |

---

## 4. Implementação em um Novo Banco (Checklist)

Cenário: adicionar renovação automática para o Banco Bradesco (237), seguindo a mesma arquitetura.

### Passo 1. Implementar a interface em `Api/Banco/NovoBanco.php`

```php
use Eduardokum\LaravelBoleto\Contracts\Api\CertificadoRenovavel;

class Bradesco extends AbstractAPI implements CertificadoRenovavel { /* ... */ }
```

### Passo 2. Adicionar propriedades, parâmetros e getters/setters

Identificar quais dados a API do novo banco exige para a renovação (por exemplo, `organization_id`, `agency`). Declarar no construtor via `$params['...']` e expor getters/setters, como em `Itau.php` (`setOuAppCertificado`, `setCidadeEmpresa`, `setUfEmpresa`).

### Passo 3. Implementar `extrairValidadeCertificado()`

Na maioria dos casos, delegar diretamente ao helper:

```php
public function extrairValidadeCertificado(): ?\DateTimeInterface
{
    $crt = (string) $this->getCertificado();

    return $crt !== '' ? \Eduardokum\LaravelBoleto\Util\CertificateUtil::extrairValidadeCertificado($crt) : null;
}
```

### Passo 4. Implementar `renovarCertificado(array $params = []): CertificadoRenovado`

Seguir o padrão do Itaú:

1. Validar os campos obrigatórios e a validade.
2. Gerar o novo par chave privada + CSR com `CertificateUtil::gerarChavePrivadaRSA2048Pem()` e `gerarCSR(...)`.
3. Autenticar com o fluxo OAuth2 já existente do banco.
4. Chamar o endpoint específico do banco (usando `withBaseUrl(...)` e o wrapper POST/GET/PATCH existente, ou `postRawTextInterno` quando o body precisar ser `text/plain` puro).
5. Fazer o parse da resposta e obter o certificado PEM.
6. Validar o novo certificado e confirmar validade estritamente maior.
7. Retornar `new CertificadoRenovado(...)`.

### Passo 5. Implementar `suportaRenovacaoAutomatica(): bool`

Retornar `true`, salvo quando o banco oferecer apenas renovação manual assistida.

### 4.1 Integração na Aplicação

- Persistência: os campos de controle são genéricos (`DataVencimentoCertificado`, `OUAppCertificado`, `DataUltimaRenovacaoCertificado`), de modo que a inclusão de um novo banco não exige nova estrutura de banco.
- Serviço e Comando: o serviço de renovação percorre todas as carteiras e utiliza `instanceof CertificadoRenovavel`; não é necessária alteração para um novo banco.
- Configuração por carteira: o valor de `OUAppCertificado` (override de OU/L/UF) pode ser preenchido individualmente ou mantido o padrão de ambiente.

---

## 5. Integração na Aplicação (Ciclo Completo)

- Execução diária às 06:00: `php artisan certificados:verificar-renovacao`, agendada sem sobreposição de execuções.
- Validação (safe-first): para cada carteira, a validade é extraída diretamente do .crt via OpenSSL; em caso de falha, utiliza-se o campo `DataVencimentoCertificado` do banco como fallback, com registro de WARNING.
- Janela de 7 dias (configurável): quando o certificado tiver 7 dias ou menos de validade restante:
  1. É criado um backup em `storage/app/certificados/backups/{id_carteira}/{YYYYMMDDHHMMSS}/` com os arquivos anteriores.
  2. Inicia-se a transação de banco.
  3. Chama-se `$api->renovarCertificado()`.
  4. Persistem-se o novo CRT e a nova KEY no storage, com permissão 0640 na chave.
  5. Atualiza-se `carteiracobranca` com os novos caminhos ou blobs, `DataVencimentoCertificado` e `DataUltimaRenovacaoCertificado = NOW()`.
  6. Executa-se o COMMIT apenas ao final. Qualquer falha provoca ROLLBACK e mantém o certificado anterior, sem estados inconsistentes.
- Qualquer Throwable encaminha para `notificarErro()`, que envia e-mail ao destinatário configurado com o assunto `[ALERTA RENOVAÇÃO CERTIFICADO ITAÚ FALHOU]`, stack resumida, dados da carteira e instrução de intervenção manual.

### Variáveis de Ambiente (`config/certificados.php`)

```env
# =====================
# RENOVAÇÃO DE CERTIFICADOS
# =====================
CERTIFICADOS_DIAS_ANTECEDENCIA=7
CERTIFICADOS_DIAS_JANELA_MAXIMA_ITAU=30
CERTIFICADOS_EMAIL_ALERTA=tecnico@exemplo.com.br
CERTIFICADOS_OU_PADRAO=SISTEMA-COBRANCA
CERTIFICADOS_CIDADE_PADRAO=SAO PAULO
CERTIFICADOS_UF_PADRAO=SP
CERTIFICADOS_SCHEDULE=06:00
```

### Validação e Testes

```bash
# 1. Verificar a sintaxe dos arquivos alterados.
php -l vendor/eduardokum/laravel-boleto/src/Api/Banco/Itau.php

# 2. Listar/validar sem alterar nada (indicado na primeira execução em produção).
php artisan certificados:verificar-renovacao --dry-run

# 3. Ajustar a antecedência de disparo (o serviço limita ao máximo de 30 dias, exigido pela API Itaú).
php artisan certificados:verificar-renovacao --dias=30

# 4. Pré-visualizar a migration.
php artisan migrate --pretend
```

---

## 6. Segurança e Checklist Operacional

- Extensão OpenSSL: `extension_loaded('openssl')` é validada no construtor do serviço; quando ausente, é lançada uma Exception clara com orientação de habilitação no `php.ini`.
- Permissões do arquivo de chave: aplicado `chmod 0640` após a gravação; o arquivo nunca fica legível por todos.
- Não registrar chaves: o conteúdo da chave privada nunca deve ser registrado em log. Quando for necessário correlacionar, utiliza-se `hash('sha256', $keyPem)`.
- Fluxo único v2: a renovação utiliza exclusivamente `/seguranca/v2/certificado/renovacao`, de forma 100% automática, sem intervenção humana e sem token temporário por e-mail. Não há fallback para a v1.
- Cabeçalho Authorization: as rotinas de `debugSalvarPayload` substituem o Bearer token por `[OCULTADO]`.
- Atualização atômica do banco: `beginTransaction()` / `rollBack()` em Throwable / `commit()` com um único update ao final do método, impedindo o cenário de "client_secret gravado sem o arquivo CRT correspondente".

---

## 7. Troubleshooting

| Sintoma | Resolução |
|:------- |:--------- |
| "Aguardando janela 30 dias antes…" (WARNING) | Comportamento normal. A execução volta a tentar diariamente até atingir ≤ 30 dias antes do vencimento. |
| Erro OpenSSL "private_key_bits invalid" | Conferir se o PHP é 8.3 e se a extensão OpenSSL está carregada (`php -m`). |
| "Campos obrigatórios ausentes para renovação de certificado Itaú 341" | Verificar se a migration foi aplicada e se o cadastro da carteira contém `APIClientID`, `APICertificado` e `APICertificadoKey` não vazios. |
| Backup não criado | Verificar permissões de escrita em `storage/app/certificados/backups/` (`chmod 0750`) e se o usuário do cron é o proprietário da pasta. |
| Falhas consecutivas por vários dias | Ação manual: abrir chamado técnico interno e desabilitar temporariamente o agendamento para evitar excesso de e-mails. |
