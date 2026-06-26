# WebP Product Cards

Plugin WordPress que converte as imagens dos **cards de produtos** (WooCommerce + Elementor Pro) para **WebP** e as entrega com fallback automático via `<picture>`, sem quebrar navegadores antigos. A versão 2 traz uma **arquitetura modular com observabilidade** (request-id, logs JSON, health check, métricas, cache hit/miss, query logging e alertas).

## Como ele resolve "converter só os cards de produto"

Esse era o ponto difícil. A estratégia tem duas pontas:

1. **Geração escopada** — um `.webp` só é gerado quando a imagem **é a destacada ou da galeria de um produto WooCommerce**. O gatilho é a mudança de `_thumbnail_id` / `_product_image_gallery` em um post do tipo `product` (não em qualquer upload). A conversão em massa também processa **apenas** imagens de produto.
2. **Entrega escopada** — o `<picture>` com WebP é injetado só nos contextos de card:
   - `woocommerce_product_get_image` → loop padrão e widget **Produtos** do Elementor.
   - `post_thumbnail_html` (restrito a `post_type = product` e fora da página individual) → **Loop Grid** do Elementor e archives.

O original **nunca é apagado nem alterado** — o `.webp` é criado ao lado.

## Arquitetura

```
webp-product-cards/
├── webp-product-cards.php        # Bootstrap: constantes + autoloader PSR-4 + boot
├── uninstall.php
├── composer.json · phpunit.xml.dist
├── includes/
│   ├── Plugin.php                # Orquestrador (singleton)
│   ├── Activator.php             # Ativação + self health-check (req 10)
│   ├── Deactivator.php
│   ├── Support/                  # Camada de observabilidade
│   │   ├── RequestContext.php    # req 1  — request-id único
│   │   ├── ErrorHandler.php      # req 2  — stack trace completo em texto
│   │   ├── Logger.php            # req 3  — logs JSON estruturados
│   │   ├── Database.php          # req 5  — query logging com tempo
│   │   ├── Cache.php             # req 6  — cache com hit/miss
│   │   ├── Metrics.php           # req 7  — tempo / memória / CPU
│   │   └── Alerts.php            # req 9  — alertas configuráveis
│   ├── Conversion/
│   │   ├── WebPConverter.php     # motor Imagick/GD (instrumentado)
│   │   ├── ImageDetector.php     # decide se é card de produto
│   │   └── AttachmentHooks.php   # gatilhos escopados + job assíncrono
│   ├── Frontend/FrontendServing.php   # entrega <picture> nos cards
│   ├── Admin/Settings.php · BulkConverter.php
│   └── Rest/RestController.php · HealthController.php   # req 4 — /health
├── tests/                        # req 8 — regressão (PHPUnit)
└── .github/workflows/deploy.yml  # req 10 — monitoramento + rollback no CI
```

## Mapa dos 10 requisitos

| # | Requisito | Onde |
|---|-----------|------|
| 1 | Request ID único por endpoint | `Support/RequestContext.php`; header `X-Request-Id` em respostas REST (`Rest/RestController.php`) e nos corpos AJAX |
| 2 | Erro com stack trace completo em texto | `Support/ErrorHandler.php` — `guard()` + `getTraceAsString()` + handler de fatais |
| 3 | Log estruturado em JSON | `Support/Logger.php` — 1 linha = 1 objeto JSON em `uploads/webp-product-cards/logs/` |
| 4 | Health check detalhado | `Rest/HealthController.php` — `GET /wp-json/webp-product-cards/v1/health` (200 healthy/degraded · 503 unhealthy) |
| 5 | Acesso ao banco com logging e tempo | `Support/Database.php` — cronometra cada query, marca lentas |
| 6 | Cache com hit/miss | `Support/Cache.php` — contadores + razão de acerto (exposto no /health e /metrics) |
| 7 | Métricas de performance (tempo/memória/CPU) | `Support/Metrics.php` — spans + `getrusage()` |
| 8 | Testes de regressão dos fluxos críticos | `tests/` — conversão JPG/PNG, formato do log, escopo |
| 9 | Alertas configuráveis para anomalias | `Support/Alerts.php` — e-mail/webhook por limiar configurável |
| 10 | Deploy com monitoramento + rollback | `.github/workflows/deploy.yml` (CI) + self health-check em `Activator.php` |

> **Sobre o #10 (transparência):** um plugin não controla o próprio deploy. O **rollback automático real** vive no pipeline (`deploy.yml`): após o deploy ele consulta `/health` e, se ficar `unhealthy`, reverte para a tag anterior. O plugin contribui com o *self health-check* na ativação e com o endpoint que o CI monitora.

## Requisitos de ambiente

- WordPress 5.6+ e PHP 7.4+
- WebP via **Imagick (WEBP)** *ou* **GD com `imagewebp()`** (a maioria das hospedagens tem GD)
- WooCommerce (para o escopo "cards de produto"; o modo "todas as imagens" funciona sem ele)

O painel **WebP Cards** mostra o motor ativo e um link para o health check.

## Instalação

**Opção A — painel:** compacte a pasta `webp-product-cards` em `.zip` e use *Plugins → Adicionar novo → Enviar plugin*.
**Opção B — cópia:** copie a pasta inteira para `wp-content/plugins/` e ative em *Plugins*.

## Uso

1. Menu **WebP Cards**. Confira o motor e a contagem de produtos.
2. Ajuste **qualidade** (80–85), **escopo** (Somente cards de produto) e, se quiser, **alertas** (e-mail/webhook) e **nível de log**.
3. **Converter agora** gera os `.webp` dos produtos existentes (barra de progresso).
4. Verifique em **DevTools → Network → Img**: os cards devem carregar `.webp`.

### Observabilidade na prática

- **Logs:** `wp-content/uploads/webp-product-cards/logs/app-AAAA-MM-DD.log` (JSON por linha; a pasta é protegida por `.htaccess`).
- **Health:** `GET /wp-json/webp-product-cards/v1/health`
- **Métricas:** `GET /wp-json/webp-product-cards/v1/metrics` (requer login admin)
- **Hooks de integração:** `wpc_log` (encaminhar para Loki/ELK), `wpc_alert` (Slack/PagerDuty).

## Testes

```bash
composer install
composer test          # ou: php vendor/bin/phpunit
```

Os testes de regressão rodam **sem subir um WordPress completo** (stubs em `tests/bootstrap.php`) e cobrem a conversão real com GD, o formato do log JSON e a lógica de escopo. Última execução: **11 testes, 28 asserções, OK**.

## Notas

- Originais **não** são apagados; o `.webp` é criado ao lado.
- Usa CDN/cache? Limpe o cache após converter.
- Conversão de upload roda em background (`wp_schedule_single_event`) para não travar o salvamento do produto.
