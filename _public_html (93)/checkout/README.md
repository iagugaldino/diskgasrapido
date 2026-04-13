# Estrutura do Projeto

```
/                          ← raiz pública (public_html ou www)
├── index.html             ← loja (cardápio)
├── checkout.html          ← página de checkout
├── css/
│   └── custom-colors.css
├── uploads/               ← imagens dos produtos
├── api/
│   ├── process-checkout.php    ← processa pedido → GhostsPay + Utmify (pending)
│   └── webhook-ghostspay.php   ← recebe confirmação → Utmify (paid)
└── logs/                  ← logs diários (protegido por .htaccess)
    └── .htaccess

```

## Configurações (já embutidas nos arquivos PHP)

| Variável             | Valor                                         |
|----------------------|-----------------------------------------------|
| GHOSTSPAY_SECRET     | sk_auto_IcoA9n92RSUwO91cfmSaRwp2JK0dXFpV    |
| GHOSTSPAY_PUBLIC     | pk_auto_6xR7pSzf6vBTRKYygm064PbamvLsL6kt    |
| UTMIFY_TOKEN         | v4EDoWyq83Zd4xDfEtQ3rZklw6Tcg284QiNP        |

## Webhook GhostsPay

Configure no painel GhostsPay a URL:
`https://seudominio.com/api/webhook-ghostspay.php`

Evento: `transaction.paid`

## Logs

Os logs ficam em `/logs/YYYY-MM-DD.log` com entradas como:

```
[2026-04-11 12:00:00] [INFO] [GHOSTSPAY_REQUEST] { ... }
[2026-04-11 12:00:01] [INFO] [GHOSTSPAY_OK] Transação criada: txn_abc123
[2026-04-11 12:00:01] [INFO] [UTMIFY_PENDING_OK] Venda pendente enviada. OrderId: ORD-...
[2026-04-11 12:00:45] [INFO] [WEBHOOK_RECEIVED] { event: "transaction.paid" ... }
[2026-04-11 12:00:45] [INFO] [UTMIFY_PAID_OK] Venda PAGA enviada para Utmify. OrderId: ORD-...
```

## Requisitos do servidor

- PHP 7.4+
- Extensão cURL habilitada
- Permissão de escrita na pasta `/logs/`
