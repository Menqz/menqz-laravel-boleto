# Laravel Boleto – Fork Corporativo Menqz Tecnologia

Este repositório é um **fork corporativo** do pacote open-source  
**eduardokum/laravel-boleto**, mantido pela **Menqz Tecnologia**, com ajustes internos e compatibilidade estendida para versões mais recentes do Laravel.

---

## Sobre este fork

Este fork foi criado para atender necessidades específicas de projetos internos da Menqz, incluindo:

- Compatibilidade com Laravel 12
- Ajustes arquiteturais e correções específicas
- Estabilidade para uso em ambientes de produção
- Integração com sistemas ERP desenvolvidos pela Menqz

O repositório original permanece como fonte principal do projeto.

🔗 Repositório original:  
https://github.com/eduardokum/laravel-boleto

---

## ⚠️ Importante

Este fork:
- **Não substitui oficialmente** o pacote original
- **Não possui suporte público**
- É utilizado exclusivamente em projetos mantidos pela Menqz

Para uso geral ou suporte da comunidade, utilize o repositório original.

---

## Instalação (uso interno)

Este pacote é utilizado via Composer apontando para o repositório VCS do fork:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/menqz/menqz-laravel-boleto"
  }
]
````

```bash
composer require eduardokum/laravel-boleto
```

---

## Compatibilidade

| Laravel    | Suporte             |
| ---------- | ------------------- |
| 6.x – 11.x | ✔️                  |
| 12.x       | ✔️ (via fork Menqz) |

---

## Licença

Este projeto continua licenciado sob a **MIT License**, conforme o projeto original.

Todos os créditos de autoria original pertencem a **Eduardo Kum**.
As modificações adicionais são mantidas pela **Menqz Tecnologia**.

---

## Menqz Tecnologia

A Menqz desenvolve soluções tecnológicas sob medida, incluindo ERPs, sistemas SaaS e integrações financeiras.

🌐 [https://menqz.com.br](https://menqz.com.br)
