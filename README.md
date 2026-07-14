# Squid Report para pfSense

Relatorio nativo do pfSense para trafego do Squid + SquidGuard, com graficos
(Chart.js), filtro por usuario e por periodo, gráfico de redes sociais e
exportacao para PDF (via impressao do navegador). Alternativa moderna ao
LightSquid, sem depender de infraestrutura externa - roda 100% dentro do
proprio pfSense.

## Recursos

- Banda diaria, requisicoes e bloqueios num dashboard integrado ao menu nativo (**Status > Relatorios Squid**)
- Categorias bloqueadas, lidas do log dedicado do SquidGuard (`block.log`)
- Identificacao de usuario real (nao so IP), cruzando o log do SquidGuard com o access.log do Squid
- Grafico dedicado de redes sociais (Facebook, Instagram, X, TikTok, LinkedIn, YouTube etc), bloqueado vs liberado
- Filtro por usuario e por intervalo de datas customizado
- Exportacao para PDF via impressao do navegador (sem dependencias extras no firewall)
- Cron incremental (le so as linhas novas do log a cada execucao, nao reprocessa tudo)

## Requisitos

- pfSense com os pacotes **Squid** e **SquidGuard** instalados e configurados
- SquidGuard com log habilitado (`enable_log` / `enable_guilog` em Services > SquidGuard Proxy Filter > General settings) - sem isso, o relatorio ainda funciona mas sem categorias/usuarios reais, so IP e bloqueado/liberado genericos
- Acesso de shell (SSH ou console) com privilegios de admin

## Instalacao

No shell do pfSense, entre no `sh` primeiro (o shell padrao `tcsh` interpreta
`!` como expansao de historico e pode travar em alguns comandos):

```sh
sh
fetch -o - https://raw.githubusercontent.com/fabianobelo/squid_report/main/install.sh | sh
```

Isso baixa os arquivos, registra o menu **Status > Relatorios Squid**, cria o
job de cron (a cada 5 minutos) e roda o parser pela primeira vez.

### Configuracao pos-instalacao

Abra `/usr/local/pfSense/squid_report/squid_report_parser.php` e confira/ajuste
no topo do arquivo:

```php
$CONFIG = [
    'access_log'      => '/var/squid/logs/access.log',   // ajuste ao seu ambiente
    'squidguard_log'  => '/var/squidGuard/log/block.log', // idem
    ...
];
```

Esses caminhos variam um pouco entre instalacoes. Para localizar o seu:

```sh
find / -iname "access.log" -path "*squid*" 2>/dev/null
find / -iname "*squidguard*" -path "*log*" 2>/dev/null
```

A lista de dominios considerados "redes sociais" para o grafico dedicado fica
em `squid_report_lib.php`, na constante `SQUID_REPORT_SOCIAL_DOMAINS` - edite
conforme a sua politica.

## Desinstalacao

```sh
sh
fetch -o - https://raw.githubusercontent.com/fabianobelo/squid_report/main/uninstall.sh | sh
```

Isso remove o menu, o cron e os arquivos PHP, mas **mantem** o historico
coletado em `/var/db/squid_report`. Para apagar tudo, incluindo o historico:

```sh
fetch -o - https://raw.githubusercontent.com/fabianobelo/squid_report/main/uninstall.sh | PURGE=1 sh
```

## Estrutura do repositorio

```
.
├── install.sh                    # instalador
├── uninstall.sh                  # desinstalador
├── files/                        # arquivos copiados 1:1 para o filesystem do pfSense
│   └── usr/local/
│       ├── www/squid_report.php
│       └── pfSense/squid_report/
│           ├── squid_report_lib.php
│           └── squid_report_parser.php
└── scripts/
    ├── pfsense_register.php      # registra menu + cron no config.xml
    └── pfsense_unregister.php    # remove menu + cron do config.xml
```

## Como funciona

- **squid_report_parser.php** roda via cron a cada 5 minutos: le incrementalmente
  o `access.log` do Squid (banda, hits, top sites) e o `block.log` do SquidGuard
  (categoria real de bloqueio, usuario, IP), grava tudo num banco SQLite local
  e gera um `cache.json` com o resumo padrao (todos os usuarios, ultimos 7 dias).
- **squid_report.php** le o `cache.json` para a visao padrao (rapido) ou consulta
  o SQLite diretamente quando ha filtro de usuario/data ativo (visao sob demanda).
- O cruzamento IP → usuario e feito automaticamente a partir do `block.log`
  (que registra qual usuario autenticado gerou cada requisicao), permitindo
  mostrar nomes reais em vez de apenas enderecos IP.

## Contribuindo

Pull requests sao bem-vindos. Ideias de melhoria futuras: suporte a
autenticacao Kerberos/NTLM no lugar de LDAP puro, exportacao CSV/Excel,
alertas via webhook quando um usuario ultrapassa uma cota de banda.

## Licenca

MIT - veja [LICENSE](LICENSE).
