# JetBooking Expiration Guard

[![Pix](https://img.shields.io/badge/Pix-Apoie-32BCAD?style=flat&logo=pix&logoColor=white)](#-apoie-o-projeto)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-Apoie-FF5E5B?style=flat&logo=ko-fi&logoColor=white)](https://ko-fi.com/fellipesalazar)
[![Buy Me a Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-Apoie-FFDD00?style=flat&logo=buy-me-a-coffee&logoColor=black)](https://www.buymeacoffee.com/fellipesalazar)

Plugin para WordPress que cancela automaticamente reservas do **JetBooking** que ficam pendentes além de um tempo configurável — liberando a data para novas reservas — sem o bug de race condition que pode cancelar reservas **já pagas**.

Funciona com ou sem WooCommerce. Se houver um pedido WooCommerce vinculado à reserva, o plugin faz uma checagem extra de segurança antes de cancelar.

## O problema que ele resolve

O JetBooking tem uma opção nativa (**Advanced → Automatically switch bookings statuses**) para cancelar automaticamente reservas que ficam pendentes por muito tempo — útil para liberar datas de checkouts abandonados.

O problema: essa automação decide se cancela olhando para o **status interno da reserva** no JetBooking, não para o status real do pedido/pagamento. Em cenários de alta concorrência — por exemplo, um webhook de pagamento chegando bem perto do fim da janela de expiração — é possível que uma reserva **já paga** ainda apareça como pendente no momento exato em que a varredura roda, e acabe sendo cancelada mesmo assim. Isso pode resultar em:

- Cliente que pagou e teve a reserva cancelada sem saber.
- A data sendo liberada e reservada por outra pessoa, gerando overbooking.

Este plugin substitui essa automação por uma versão com duas camadas de proteção:

1. **UPDATE atômico e condicional** — o cancelamento só acontece se, no exato momento da escrita, a reserva ainda estiver no status-alvo (ex: `pending`). Fecha a janela de corrida entre "pagamento sendo confirmado" e "varredura de expiração rodando".
2. **Checagem opcional do pedido WooCommerce** — se a reserva tiver um pedido vinculado e esse pedido já não estiver mais em um status de "aguardando pagamento", a reserva **não é cancelada**, mesmo que o registro interno do JetBooking ainda diga "pending".

## Como funciona

O plugin não depende de nenhum hook específico do JetBooking nem de triggers de banco de dados (que exigem permissões elevadas, nem sempre disponíveis em hospedagem compartilhada). Em vez disso:

1. A cada execução, sincroniza reservas novas da tabela do JetBooking (`{prefix}jet_apartment_bookings`) para uma tabela própria de controle, usando o `booking_id` como marca d'água — o custo é proporcional só ao que é novo, não ao tamanho total da tabela.
2. Busca, nessa tabela de controle, reservas que passaram do tempo de expiração configurado e ainda não foram processadas.
3. Para cada uma: se aplicável, confere o pedido WooCommerce vinculado; se ainda estiver pendente, faz o UPDATE condicional trocando o status.

### Execução — três camadas, sem precisar de acesso ao servidor

- **WP-Cron** nativo do WordPress, agendado para rodar a cada minuto.
- **Rede de segurança** no hook `init`: roda a mesma varredura em qualquer visita ao site (limitada a uma vez a cada ~55s), cobrindo o caso comum de `DISABLE_WP_CRON` estar ativo ou o WP-Cron não disparar por falta de tráfego.
- **Endpoint de ping externo (opcional)**: uma rota REST (`/wp-json/jbeg/v1/ping`) que pode ser chamada por um serviço gratuito como [cron-job.org](https://cron-job.org), garantindo execução mesmo em horários sem nenhum visitante real.

## Requisitos

- WordPress com o plugin **JetBooking** (Crocoblock) ativo, no modo integrado ao WooCommerce.
- A tabela `{prefix}jet_apartment_bookings` precisa existir e ter as colunas `booking_id`, `status` e `order_id` (padrão do JetBooking).
- WooCommerce é opcional — a checagem extra de segurança só é usada se ele estiver ativo e a reserva tiver um `order_id` vinculado.

## Instalação

1. Baixe este repositório (ou clone) e compacte a pasta em `.zip`.
2. No WordPress: **Plugins → Adicionar novo → Enviar plugin**, selecione o `.zip` e ative.
3. Vá em **Configurações → Expiration Guard**.

## Configuração

Na tela de configurações, a seção **"Status encontrados na sua tabela de reservas"** mostra os valores reais de status já usados no seu banco — use um deles no campo abaixo.

| Campo | O que faz |
|---|---|
| **Ativo** | Liga/desliga o plugin sem precisar desativá-lo. |
| **Tempo de expiração (minutos)** | Quanto tempo uma reserva pode ficar no status-alvo antes de ser cancelada. |
| **Status considerado pendente** | O valor de status (ex: `pending`) que o plugin trata como "aguardando confirmação". |
| **Novo status ao expirar** | Para qual status a reserva muda ao expirar (ex: `cancelled`). |
| **Checar pedido WooCommerce antes de cancelar** | Se ligado, não cancela reservas cujo pedido vinculado já não esteja mais aguardando pagamento. Recomendado manter ligado se o site usa WooCommerce. |

A seção **"Status do cron"** mostra o horário da última execução e quantas reservas foram avaliadas — útil para confirmar que o plugin está rodando de fato.

> ⚠️ **Importante:** desative a automação nativa do JetBooking (**Advanced → Automatically switch bookings statuses**) antes de ativar este plugin, para os dois não competirem pelo mesmo trabalho.

## Aviso

Este plugin escreve diretamente na tabela de reservas do JetBooking. Teste em um ambiente de staging antes de usar em produção, e confirme que os valores de status configurados correspondem exatamente aos usados no seu banco (a comparação é case-sensitive).

Desenvolvido para uma configuração específica de JetBooking + WooCommerce; nomes de tabela ou colunas podem variar entre versões do plugin — confira o schema da sua própria tabela antes de usar.

## ☕ Apoie o projeto

Se esse plugin te ajudou, considere apoiar:

- **Pix:** `12a73dd8-65c9-4e43-a7f7-a7004d0a9d9c` (copie e cole no app do seu banco)
- **Ko-fi:** [https://ko-fi.com/fellipesalazar](https://ko-fi.com/fellipesalazar)
- **Buy Me a Coffee:** [buymeacoffee.com/fellipesalazar](https://www.buymeacoffee.com/fellipesalazar)

## Licença

MIT — sinta-se livre para usar, modificar e distribuir.
